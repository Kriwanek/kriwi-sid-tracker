# Bausteine & konkrete GitHub-Projekte

Die folgenden Vorschläge sind bewusst zielgerichtet: jedes Projekt ist erprobt,
aktiv gewartet und lässt sich als Baustein in einen SID-Tracker integrieren.

| Baustein | Zweck | Konkretes GitHub-Projekt | Begründung |
| --- | --- | --- | --- |
| Web-UI | React-UI für Dashboard, Filter, Details | https://github.com/refinedev/refine | Admin-/Dashboard-Framework mit Auth, CRUD und Tabellen out-of-the-box. |
| UI-Komponenten | Design-System & Komponenten | https://github.com/mui/material-ui | Breites, stabiles React-Komponenten-Set; gute Tabellen & Formulare. |
| API-Framework | REST/GraphQL API | https://github.com/fastapi/fastapi | Schnelles Python-API-Framework, Typen, OpenAPI, einfache Auth. |
| DB-ORM | Datenmodellierung | https://github.com/sqlalchemy/sqlalchemy | De-facto-Standard für Python-ORM mit Migrationen (Alembic). |
| Datenaufnahme | Scraping/Crawling | https://github.com/scrapy/scrapy | Robustes Crawling-Framework mit Pipelines & Scheduling. |
| Jobs/Queues | Hintergrundjobs | https://github.com/celery/celery | Bewährt für Task-Queues; integrierbar mit FastAPI. |
| Search | Volltextsuche & Facetten | https://github.com/elastic/elasticsearch | Industriestandard für Suche, Filter & Aggregationen. |
| Observability | Logging & Tracing | https://github.com/open-telemetry/opentelemetry-python | Standard für Tracing/Metrics; gute Integration mit FastAPI. |
| Auth | Self-hosted Auth | https://github.com/supertokens/supertokens-core | Flexible Auth-Engine mit SDKs und Session-Management. |
| Infra/Deploy | Container & Deployment | https://github.com/docker/compose | Einheitliches lokales Setup + einfache CI/CD. |

## Hinweise zur Auswahl

- **FastAPI + SQLAlchemy + Alembic** bilden ein schlankes, schnelles API-Backbone.
- **Scrapy + Celery** trennen Erfassung (Crawler) von Verarbeitung (Jobs).
- **refine + MUI** beschleunigen die Web-UI (Tabellen/Filter/CRUD).
- **Elasticsearch** ist optional, falls Volltextsuche und Facetten nötig sind.
