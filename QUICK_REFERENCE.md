# Clip Matters - Quick Reference Card

## Live URLs
- Main App: https://clip.digitalmatters.pk
- Python Search API: https://clip-matter-service.up.railway.app
- Health Check: https://clip-matter-service.up.railway.app/health

## Tech Stack
PHP 8.2 + Laravel 12 (Backend), React 18 + Inertia.js (Frontend),
MySQL (DB), Redis (Queue), Typesense (Full-text Search),
Qdrant Cloud (Vector/Semantic Search), Python FastAPI (Microservice),
Dropbox (File Storage), YouTube (Video Hosting),
AssemblyAI (Transcription), Pyannote (Speaker ID), OpenAI (AI features)

## User Roles
superAdmin > admin > manager > user
- superAdmin: Full access, owns Dropbox (all inherit from them)
- admin: Full video mgmt, own YouTube
- manager: Video management only, inherits admin YouTube
- user: Search only (no admin panel)

## Video Lifecycle
Dropbox Upload
  -> Webhook/Manual Sync
  -> ProcessVideoJob (background ~10-30 min)
     [Download -> Audio Extract -> Transcribe -> Speaker ID
      -> YouTube Upload -> Translate -> Summarize]
  -> Admin Reviews (VideoApproval page)
  -> Admin Approves
  -> GenerateVideoEmbedding dispatched
  -> SEARCHABLE via Typesense + Qdrant

## Database Tables
| Table              | Purpose                              |
|--------------------|--------------------------------------|
| users              | Auth + Dropbox/YouTube OAuth tokens  |
| videos             | Core video + transcript JSON data    |
| video_embeddings   | Qdrant embedding status per video    |
| voice_samples      | Speaker voiceprints for speaker ID   |
| video_tags         | Manual tags assigned to videos       |
| search_logs        | Analytics for all user searches      |
| dropbox_deletions  | Track deleted Dropbox files          |

## Key Files
| File | What It Does |
|------|-------------|
| app/Jobs/ProcessVideoJob.php | Core 10-step video pipeline (1926 lines) |
| app/Http/Controllers/VideoController.php | Main controller + search (3863 lines) |
| app/Services/DropboxService.php | All Dropbox API calls |
| app/Services/AssemblyAiService.php | Transcription + speaker ID |
| app/Services/SpeakerTaggingService.php | Merge AssemblyAI + Pyannote speakers |
| "Clip matter py  embeding/embeddings_test.py" | Python semantic search (6700+ lines) |
| routes/web.php | All HTTP routes (1241 lines) |
| resources/js/Pages/UserSide/SearchedPage.jsx | Search results UI (72KB) |

## Critical Commands
  # Start development (Laravel + Queue + Vite)
  composer run dev

  # Run queue worker (REQUIRED for video processing)
  php artisan queue:work redis --tries=2 --timeout=3600 --queue=high,default

  # Database migrations
  php artisan migrate

  # Re-embed all videos after Qdrant reset
  php artisan embeddings:reembed-all --force

  # Fix duplicate records
  php artisan videos:cleanup-duplicates

  # Rebuild autocomplete phrase index
  php artisan search:extract-phrases

## Environment Variables (Critical)
  DB_*                         MySQL connection
  QUEUE_CONNECTION=redis        MUST be redis
  DROPBOX_APP_KEY/SECRET        Dropbox OAuth app
  GOOGLE_CLIENT_ID/SECRET       YouTube OAuth
  ASSEMBLYAI_API_KEY            Transcription service
  PYANNOTE_API_KEY              Speaker identification
  OPENAI_API_KEY                AI embeddings + translations
  PYTHON_EMBEDDING_SERVICE_URL  Python microservice URL
  TYPESENSE_API_KEY/HOST        Search engine

## Search System
  User Query
    -> Laravel VideoController::search()
         -> Python Service /search (semantic/Qdrant)
         -> Typesense Scout (keyword)
    -> Merge + deduplicate results
    -> Return to React SearchedPage.jsx

  Modes: semantic | keyword | hybrid

## Dropbox Webhook
  URL: https://clip.digitalmatters.pk/webhook/dropbox
  Security: HMAC-SHA256 via DROPBOX_APP_SECRET
  Register at: https://www.dropbox.com/developers/apps

## Common Issues & Fixes
| Issue | Fix |
|-------|-----|
| Videos not processing | Check queue worker is running |
| Search returns wrong results | Check Typesense + re-import scout |
| Semantic search broken | Check Python service /health endpoint |
| Dropbox not syncing | Check webhook + SuperAdmin connection |
| Speaker names show A/B/C | Add voice samples in Admin > Voice Samples |
| Urdu search not matching | Fixed via SearchNormalizationService |

Full documentation: PROJECT_DOCUMENTATION.md
