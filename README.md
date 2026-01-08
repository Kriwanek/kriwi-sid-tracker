# kriwi-sid-tracker

Ein leichtgewichtiges Repository, das die Bausteine für einen SID-Tracker strukturiert.
Es enthält Vorschläge für konkrete Open-Source-Projekte sowie eine Startstruktur für
Backend, Datenaufnahme und Web-UI.

## Repo-Struktur

```
.
├── data/                 # Datenablagen, Beispieldaten, Schemas
├── docs/                 # Architektur, Bausteine, Entscheidungen
├── infra/                # Deployment, Container, CI/CD
├── packages/             # Geteilte Libraries/SDKs
│   └── shared/
└── services/
    ├── api/              # API-Service (Suche, CRUD, Auth)
    ├── collector/        # Datenaufnahme/Parser
    └── web/              # Web-UI
```

## Bausteine & Projektvorschläge

Siehe `docs/bausteine.md` für konkrete GitHub-Projekte pro Baustein.
