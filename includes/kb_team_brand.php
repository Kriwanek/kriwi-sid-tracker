<?php
// /includes/kb_team_brand.php — zentrale Vereins-/Mannschaftsmarke für geschützte HTML-Seiten
// Build 2026-07-25 r13

declare(strict_types=1);

if (!function_exists('kb_brand_table_exists')) {
  function kb_brand_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SHOW TABLES LIKE ?');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('kb_brand_columns')) {
  function kb_brand_columns(PDO $pdo, string $table): array {
    try {
      return $pdo->query("SHOW COLUMNS FROM `".str_replace('`','',$table)."`")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('kb_brand_first_col')) {
  function kb_brand_first_col(array $cols, array $candidates): ?string {
    foreach ($candidates as $candidate) if (in_array($candidate, $cols, true)) return $candidate;
    return null;
  }
}

if (!function_exists('kb_brand_name_normalize')) {
  function kb_brand_name_normalize(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return preg_replace('/[^a-z0-9]+/u', ' ', $value) ?: '';
  }
}

if (!function_exists('kb_brand_initials')) {
  function kb_brand_initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 3) as $part) $out .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
    return $out !== '' ? $out : 'KB';
  }
}

if (!function_exists('kb_brand_safe_logo_url')) {
  function kb_brand_safe_logo_url(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (str_starts_with($value, '/')) return $value;
    if (preg_match('~^https://~i', $value)) return $value;
    if (preg_match('~^(uploads|assets)/~i', $value)) return '/'.$value;
    return '';
  }
}

if (!function_exists('kb_brand_primary_team_id')) {
  function kb_brand_primary_team_id(PDO $pdo, int $userId): int {
    if ($userId <= 0) return 0;

    if (kb_brand_table_exists($pdo, 'memberships')) {
      $mCols = kb_brand_columns($pdo, 'memberships');
      $conditions = ['user_id=:uid'];
      if (in_array('deleted_at', $mCols, true)) $conditions[] = "(deleted_at IS NULL OR deleted_at='' OR deleted_at='0000-00-00 00:00:00')";
      if (in_array('status', $mCols, true)) $conditions[] = "(status IN ('approved','active') OR status IS NULL OR status='')";
      $order = [];
      if (in_array('is_primary', $mCols, true)) $order[] = 'is_primary DESC';
      if (in_array('approved_at', $mCols, true)) $order[] = 'approved_at DESC';
      if (in_array('created_at', $mCols, true)) $order[] = 'created_at DESC';
      $order[] = 'id DESC';
      try {
        $st = $pdo->prepare('SELECT team_id FROM memberships WHERE '.implode(' AND ', $conditions).' ORDER BY '.implode(',', $order).' LIMIT 1');
        $st->execute([':uid'=>$userId]);
        $teamId = (int)($st->fetchColumn() ?: 0);
        if ($teamId > 0) return $teamId;
      } catch (Throwable $e) {}
    }

    if (kb_brand_table_exists($pdo, 'users')) {
      $uCols = kb_brand_columns($pdo, 'users');
      $candidate = kb_brand_first_col($uCols, ['default_team_id','team_id','primary_team_id']);
      if ($candidate) {
        try {
          $st = $pdo->prepare("SELECT `$candidate` FROM users WHERE id=? LIMIT 1");
          $st->execute([$userId]);
          return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {}
      }
    }
    return 0;
  }
}

if (!function_exists('kb_brand_user_can_access_team')) {
  function kb_brand_user_can_access_team(PDO $pdo, int $userId, int $teamId, string $globalRole): bool {
    if ($userId <= 0 || $teamId <= 0) return false;
    if (in_array($globalRole, ['super_admin','admin'], true)) return true;
    if (!kb_brand_table_exists($pdo, 'memberships')) return false;
    $mCols = kb_brand_columns($pdo, 'memberships');
    $conditions = ['user_id=:uid','team_id=:tid'];
    if (in_array('deleted_at', $mCols, true)) $conditions[] = "(deleted_at IS NULL OR deleted_at='' OR deleted_at='0000-00-00 00:00:00')";
    if (in_array('status', $mCols, true)) $conditions[] = "(status IN ('approved','active') OR status IS NULL OR status='')";
    try {
      $st = $pdo->prepare('SELECT 1 FROM memberships WHERE '.implode(' AND ', $conditions).' LIMIT 1');
      $st->execute([':uid'=>$userId, ':tid'=>$teamId]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('kb_brand_effective_team_id')) {
  function kb_brand_effective_team_id(PDO $pdo, int $userId, string $globalRole, int $requestedTeamId=0): int {
    $globalRole = strtolower(trim($globalRole));
    $primary = kb_brand_primary_team_id($pdo, $userId);

    if ($requestedTeamId > 0 && kb_brand_user_can_access_team($pdo, $userId, $requestedTeamId, $globalRole)) {
      return $requestedTeamId;
    }
    if ($primary > 0) return $primary;

    $sessionTeam = (int)($_SESSION['kb_team_id'] ?? $_SESSION['my_team_id'] ?? $_SESSION['team_id'] ?? 0);
    if ($sessionTeam > 0 && kb_brand_user_can_access_team($pdo, $userId, $sessionTeam, $globalRole)) return $sessionTeam;
    return 0;
  }
}

if (!function_exists('kb_brand_team_info')) {
  function kb_brand_team_info(PDO $pdo, int $teamId): array {
    $result = ['id'=>$teamId,'name'=>'Kickerbay','logo'=>'','initials'=>'KB','club_id'=>0];
    if ($teamId <= 0 || !kb_brand_table_exists($pdo, 'teams')) return $result;

    $tCols = kb_brand_columns($pdo, 'teams');
    $nameCol = kb_brand_first_col($tCols, ['name','team_name','title']);
    $clubCol = kb_brand_first_col($tCols, ['club_id','verein_id']);
    $logoCols = array_values(array_intersect(['logo_url','logo','logo_path','emblem_url','badge_url','image_url','logo_notify_url','wappen','crest'], $tCols));
    $logoExpr = $logoCols ? 'COALESCE('.implode(',', array_map(static fn($c)=>"NULLIF(TRIM(`$c`),'')", $logoCols)).')' : 'NULL';

    try {
      $select = ['id'];
      $select[] = $nameCol ? "`$nameCol` AS team_name" : "'Kickerbay' AS team_name";
      $select[] = "$logoExpr AS team_logo";
      $select[] = $clubCol ? "`$clubCol` AS club_id" : 'NULL AS club_id';
      $st = $pdo->prepare('SELECT '.implode(',', $select).' FROM teams WHERE id=? LIMIT 1');
      $st->execute([$teamId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      if ($row) {
        $result['name'] = trim((string)($row['team_name'] ?? '')) ?: 'Kickerbay';
        $result['logo'] = kb_brand_safe_logo_url((string)($row['team_logo'] ?? ''));
        $result['club_id'] = (int)($row['club_id'] ?? 0);
      }
    } catch (Throwable $e) {}

    $teamNameNorm = kb_brand_name_normalize($result['name']);

    if ($result['logo'] === '' && kb_brand_table_exists($pdo, 'kb_clubs')) {
      $cCols = kb_brand_columns($pdo, 'kb_clubs');
      $cName = kb_brand_first_col($cCols, ['name','short_name','club_name']);
      $cLogoCols = array_values(array_intersect(['logo_url','logo','logo_path','emblem_url','badge_url','image_url','wappen','crest'], $cCols));
      $cLogoExpr = $cLogoCols ? 'COALESCE('.implode(',', array_map(static fn($c)=>"NULLIF(TRIM(`$c`),'')", $cLogoCols)).')' : 'NULL';
      if ($cName && $cLogoCols) {
        try {
          $where = [];
          $params = [];
          if ($result['club_id'] > 0 && in_array('id', $cCols, true)) { $where[]='id=:cid'; $params[':cid']=$result['club_id']; }
          if (!$where) {
            $clubs = $pdo->query("SELECT id,`$cName` AS club_name,$cLogoExpr AS club_logo FROM kb_clubs ORDER BY CHAR_LENGTH(`$cName`) DESC,id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($clubs as $club) {
              $candidate = kb_brand_name_normalize((string)($club['club_name'] ?? ''));
              if ($candidate !== '' && str_starts_with($teamNameNorm, $candidate)) {
                $result['club_id'] = (int)($club['id'] ?? 0);
                $result['logo'] = kb_brand_safe_logo_url((string)($club['club_logo'] ?? ''));
                break;
              }
            }
          } else {
            $st=$pdo->prepare("SELECT `$cName` AS club_name,$cLogoExpr AS club_logo FROM kb_clubs WHERE ".implode(' AND ',$where).' LIMIT 1');
            $st->execute($params);
            $result['logo'] = kb_brand_safe_logo_url((string)($st->fetchColumn(1) ?: ''));
          }
        } catch (Throwable $e) {}
      }
    }

    if ($result['logo'] === '' && $logoCols) {
      try {
        $conditions = ['id<>:id', "$logoExpr IS NOT NULL"];
        $params = [':id'=>$teamId];
        if ($result['club_id'] > 0 && $clubCol) {
          $conditions[] = "`$clubCol`=:club_id";
          $params[':club_id']=$result['club_id'];
        } elseif ($nameCol) {
          $prefix = trim(preg_replace('/\b(U\d{1,2}|A|B|C|D|E|F|G|H|I|II|III|IV|V|\d+)\b.*$/iu', '', $result['name']) ?? $result['name']);
          if ($prefix !== '') { $conditions[] = "`$nameCol` LIKE :prefix"; $params[':prefix']=$prefix.'%'; }
        }
        $st=$pdo->prepare("SELECT $logoExpr AS fallback_logo FROM teams WHERE ".implode(' AND ',$conditions).' ORDER BY '.(in_array('is_active',$tCols,true)?'COALESCE(is_active,1) DESC,':'').'id DESC LIMIT 1');
        $st->execute($params);
        $result['logo'] = kb_brand_safe_logo_url((string)($st->fetchColumn() ?: ''));
      } catch (Throwable $e) {}
    }

    $result['initials'] = kb_brand_initials($result['name']);
    return $result;
  }
}

if (!function_exists('kb_global_brand_inject_html')) {
  function kb_global_brand_inject_html(string $html): string {
    if ($html === '' || stripos($html, '</body>') === false || stripos($html, '<body') === false) return $html;
    if (stripos($html, 'data-kb-global-team-brand') !== false || stripos($html, 'name="kb-global-brand-disable"') !== false) return $html;

    $assetVersion = '20260725-13';
    $injection = PHP_EOL
      . '<link rel="stylesheet" href="/assets/css/global_team_brand.css?v=' . $assetVersion . '">' . PHP_EOL
      . '<a id="kbGlobalTeamBrand" data-kb-global-team-brand hidden href="/dashboard.php" aria-label="Zur Mannschaftsübersicht">'
      . '<span class="kb-global-team-brand__logo"><img alt="" hidden><span class="kb-global-team-brand__initials">KB</span></span>'
      . '<span class="kb-global-team-brand__name">Mannschaft</span></a>' . PHP_EOL
      . '<script src="/assets/js/global_team_brand.js?v=' . $assetVersion . '" defer></script>' . PHP_EOL
      . '<script src="/assets/js/kb_dashboard_term_r13.js?v=' . $assetVersion . '" defer></script>' . PHP_EOL;
    return preg_replace('~</body>~i', $injection.'</body>', $html, 1) ?: $html;
  }
}

if (!function_exists('kb_global_brand_bootstrap')) {
  function kb_global_brand_bootstrap(): void {
    static $started = false;
    if ($started) return;
    $started = true;
    if (PHP_SAPI === 'cli') return;
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if ((int)($_SESSION['kb_user_id'] ?? $_SESSION['user_id'] ?? 0) <= 0) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    ob_start('kb_global_brand_inject_html');
  }
}
