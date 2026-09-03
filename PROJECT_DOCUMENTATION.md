# 📋 Clip Matters — Complete Project Documentation
> **Project Handover Reference** | Last Updated: July 2026

---

## 📌 Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technology Stack](#2-technology-stack)
3. [High-Level Architecture](#3-high-level-architecture)
4. [Project Directory Hierarchy](#4-project-directory-hierarchy)
5. [Database Schema & Models](#5-database-schema--models)
6. [User Roles & Permissions](#6-user-roles--permissions)
7. [Backend — Controllers](#7-backend--controllers)
8. [Backend — Services](#8-backend--services)
9. [Jobs (Background Processing)](#9-jobs-background-processing)
10. [Artisan Commands](#10-artisan-commands)
11. [Frontend — React Pages & Components](#11-frontend--react-pages--components)
12. [Python Semantic Search Service](#12-python-semantic-search-service)
13. [Video Processing Pipeline](#13-video-processing-pipeline)
14. [Search System Architecture](#14-search-system-architecture)
15. [Dropbox Integration](#15-dropbox-integration)
16. [YouTube/Google Integration](#16-youtubegoogle-integration)
17. [API Routes Reference](#17-api-routes-reference)
18. [Environment Variables](#18-environment-variables)
19. [Deployment & Infrastructure](#19-deployment--infrastructure)
20. [Known Issues & Fixes Applied](#20-known-issues--fixes-applied)

---

## 1. Project Overview

**Clip Matters** is a sophisticated **video content management and semantic search platform** designed for Pakistani political media content. It allows administrators to:

- **Sync videos** from Dropbox (auto-detected via webhooks or manually)
- **Transcribe** videos using AssemblyAI with speaker diarization
- **Identify speakers** using Pyannote voice biometrics against stored voiceprints
- **Upload to YouTube** for hosting
- **Generate AI embeddings** (OpenAI text-embedding-3-large, 3072-dim) for semantic search
- **Search content semantically** in Urdu, English, and Roman Urdu across video transcripts
- **Manage approvals** of videos before they appear in search results
- **Tag videos** with custom labels for improved discovery

**Live URL:** `https://clip.digitalmatters.pk`
**Python Embedding Service:** `https://clip-matter-service.up.railway.app`

---

## 2. Technology Stack

### Backend
| Technology | Version | Purpose |
|-----------|---------|---------|
| PHP | 8.2+ | Server-side language |
| Laravel | 12.x | Application framework |
| Laravel Inertia | 2.x | SPA bridge (server to React) |
| Laravel Scout | 11.x | Typesense full-text search |
| Spatie Laravel Permission | 6.x | Role-based access control |
| Typesense PHP | 6.x | Full-text search client |
| OpenAI PHP | 0.18.x | AI text generation |
| Spatie Dropbox API | 1.23.x | Dropbox file sync |
| Google API Client | 2.18.x | YouTube upload |
| AssemblyAI | REST API | Speech-to-text + diarization |

### Frontend
| Technology | Version | Purpose |
|-----------|---------|---------|
| React | 18.x | UI framework |
| Vite | 7.x | Build tool |
| TailwindCSS | 3.x | CSS framework |
| MUI (Material UI) | 7.x | Component library |
| Highcharts | 12.x | Dashboard analytics charts |
| React Player | 3.x | Video playback |
| i18next | 25.x | Internationalization (Urdu/English) |
| SweetAlert2 | 11.x | Modals and alerts |

### Python Microservice
| Technology | Version | Purpose |
|-----------|---------|---------|
| Python | 3.11+ | Runtime |
| FastAPI | 0.109 | REST API framework |
| Uvicorn | 0.27 | ASGI server |
| Qdrant | Cloud | Vector database |
| FastEmbed | 0.3+ | Local embedding fallback |
| OpenAI | 1.12+ | text-embedding-3-large |
| RapidFuzz | 3.x | Fuzzy text matching |

### Infrastructure
| Service | Purpose |
|---------|---------|
| MySQL | Primary relational database |
| Redis | Queue driver and caching |
| Typesense | Full-text search engine |
| Qdrant Cloud | Vector embeddings store |
| Railway | Python service hosting |
| Dropbox | Source video file storage |
| YouTube | Hosted video playback |
| AssemblyAI | Transcription API |
| Pyannote | Speaker identification API |
| Mailgun | Email notifications |

---

## 3. High-Level Architecture

```
USERS / BROWSERS
       |
       | HTTPS
       v
Laravel Application (PHP 8.2 + Laravel 12)
  |
  |-- Inertia.js React SPA (frontend)
  |-- REST API endpoints (web.php routes)
  |-- Dropbox Webhook Controller
  |
  v
Controllers / Services
  |-- VideoController (3800+ lines, main controller)
  |-- DropboxService    -- AssemblyAiService
  |-- GoogleApiService  -- VideoProcessingService
  |-- SpeakerTaggingService
  |
  v
Queue / Jobs (Redis)
  |-- ProcessVideoJob (core 10-step pipeline)
  |-- GenerateVideoEmbedding
  |-- ExtractVideoPhrasesJob
  |
  +-------+----------+----------+----------+
  |       |          |          |          |
  v       v          v          v          v
MySQL   Redis    Typesense   Dropbox   External APIs
(DB)   (queue)  (search)    (files)   AssemblyAI /
                                       Pyannote /
                                       OpenAI /
                                       YouTube
                                          |
                                          v
                                Python FastAPI Service
                                   (Railway deploy)
                                          |
                                          v
                                    Qdrant Cloud
                                  (Vector Database)
```

---

## 4. Project Directory Hierarchy

```
public_html/
|-- .env                           # Production environment variables
|-- .env.example                   # Environment template (safe to share)
|-- composer.json                  # PHP/Laravel dependencies
|-- package.json                   # Node.js/React dependencies
|-- artisan                        # Laravel CLI entry point
|-- vite.config.js                 # Vite build config
|-- tailwind.config.js             # TailwindCSS configuration
|
|-- app/                           # === MAIN APPLICATION CODE ===
|   |-- Console/
|   |   |-- Commands/              # 13 custom Artisan commands
|   |       |-- AuditVideoData.php
|   |       |-- CleanupDuplicateVideos.php
|   |       |-- CleanupFailedEmbeddingVideos.php
|   |       |-- CleanupQdrant.php
|   |       |-- EmbedApprovedVideos.php
|   |       |-- ExtractSearchPhrasesCommand.php
|   |       |-- FixEnglishTranslations.php
|   |       |-- FixVideoChunks.php
|   |       |-- ReEmbedVideo.php
|   |       |-- ReembedAllVideos.php
|   |       |-- RegenerateAllEmbeddings.php
|   |       |-- UpdateEmbeddingPayloads.php
|   |       `-- UpdateVoiceSampleDurations.php
|   |
|   |-- Http/
|   |   |-- Controllers/
|   |   |   |-- Admin/             # Admin-only controllers
|   |   |   |   |-- DashboardController.php
|   |   |   |   |-- SettingController.php
|   |   |   |   |-- UserController.php         (296 lines)
|   |   |   |   |-- VideoApprovalController.php (646 lines)
|   |   |   |   `-- VoiceSampleController.php  (406 lines)
|   |   |   |-- Api/               # API endpoints
|   |   |   |   |-- QdrantWebhookController.php
|   |   |   |   `-- SearchSuggestionController.php
|   |   |   |-- Auth/              # Laravel Breeze auth controllers
|   |   |   |-- Controller.php     # Base controller
|   |   |   |-- DropboxWebhookController.php  (640 lines)
|   |   |   |-- ProfileController.php
|   |   |   |-- VideoController.php            (3863 lines)
|   |   |   `-- YoutubeController.php          (935 lines)
|   |   |-- Middleware/
|   |   |   |-- CheckUserStatus.php    # Block inactive users
|   |   |   `-- HandleInertiaRequests.php  # Share auth props to React
|   |   |-- Requests/              # Form validation request classes
|   |   `-- Traits/
|   |       `-- ResponseTrait.php  # Shared API response helpers
|   |
|   |-- Jobs/                      # Background queue jobs
|   |   |-- ExtractVideoPhrasesJob.php
|   |   |-- GenerateVideoClipJob.php
|   |   |-- GenerateVideoEmbedding.php   (359 lines)
|   |   `-- ProcessVideoJob.php          (1926 lines) -- CORE PIPELINE
|   |
|   |-- Mail/
|   |   `-- VideoProcessedNotification.php  # Email after processing
|   |
|   |-- Models/                    # Eloquent ORM models
|   |   |-- DropboxDeletion.php    # Tracks deleted Dropbox files
|   |   |-- SearchLog.php          # Search analytics log
|   |   |-- User.php               # User with OAuth token management
|   |   |-- Video.php              # Core video model (Searchable)
|   |   |-- VideoEmbedding.php     # Qdrant embedding status
|   |   |-- VideoTag.php           # Manual video tags
|   |   `-- VoiceSample.php        # Speaker voiceprint samples
|   |
|   |-- Providers/                 # Laravel service providers
|   `-- Services/                  # Business logic layer
|       |-- AssemblyAiService.php       (538 lines)
|       |-- DropboxService.php          (972 lines)
|       |-- GoogleApiService.php        (248 lines)
|       |-- GoogleCloudSpeechService.php (legacy)
|       |-- ProcessService.php          (utility)
|       |-- SearchNormalizationService.php (87 lines)
|       |-- SearchPhraseExtractor.php
|       |-- SearchSuggestionService.php (185 lines)
|       |-- SpeakerTaggingService.php   (341 lines)
|       `-- VideoProcessingService.php  (447 lines)
|
|-- database/
|   |-- migrations/                # 33 database migrations (2025-2026)
|   |-- factories/                 # Test data factories
|   `-- seeders/                   # Database seeders
|
|-- resources/
|   |-- js/                        # React/Inertia frontend
|   |   |-- Components/            # 16 reusable components
|   |   |   |-- ApplicationLogo.jsx
|   |   |   |-- Checkbox.jsx
|   |   |   |-- DangerButton.jsx
|   |   |   |-- Dropdown.jsx
|   |   |   |-- InputError.jsx
|   |   |   |-- InputLabel.jsx
|   |   |   |-- LanguageSwitcher.jsx
|   |   |   |-- LoadingOverlay.jsx
|   |   |   |-- Modal.jsx
|   |   |   |-- NavLink.jsx
|   |   |   |-- PrimaryButton.jsx
|   |   |   |-- ResponsiveNavLink.jsx
|   |   |   |-- SecondaryButton.jsx
|   |   |   |-- Sidebar.jsx
|   |   |   |-- TagsModal.jsx
|   |   |   `-- TextInput.jsx
|   |   |-- Layouts/               # Page layout templates
|   |   |-- Pages/
|   |   |   |-- AdminSide/
|   |   |   |   |-- VideoApproval/
|   |   |   |   |   |-- index.jsx   # Approval tab container
|   |   |   |   |   |-- Pending.jsx
|   |   |   |   |   |-- Approved.jsx
|   |   |   |   |   |-- Rejected.jsx
|   |   |   |   |   |-- Archived.jsx
|   |   |   |   |   `-- Failed.jsx
|   |   |   |   |-- VideoManagement/
|   |   |   |   |   |-- index.jsx   # Management tab container
|   |   |   |   |   |-- All.jsx
|   |   |   |   |   |-- InProcess.jsx
|   |   |   |   |   |-- Failed.jsx
|   |   |   |   |   `-- Tracked.jsx
|   |   |   |   |-- Dashboard.jsx   (37KB) -- charts, analytics
|   |   |   |   |-- Settings.jsx
|   |   |   |   |-- UserManagement.jsx (59KB)
|   |   |   |   `-- VoiceSample.jsx    (44KB)
|   |   |   |-- UserSide/
|   |   |   |   |-- MainVideoPage/
|   |   |   |   |   `-- index.jsx   (140KB) -- video player + transcript
|   |   |   |   |-- SearchPage.jsx  (48KB) -- search landing + voice input
|   |   |   |   `-- SearchedPage.jsx (72KB) -- search results display
|   |   |   `-- Auth/
|   |   |       |-- Login.jsx
|   |   |       |-- Register.jsx
|   |   |       |-- ForgotPassword.jsx
|   |   |       |-- ResetPassword.jsx
|   |   |       `-- VerifyEmail.jsx
|   |   |-- Utils/                 # Utility/helper functions
|   |   |-- locales/               # i18n JSON translation files
|   |   |-- app.jsx                # React application entry point
|   |   |-- bootstrap.js           # Axios setup
|   |   `-- i18n.js                # i18next configuration
|   |-- css/
|   |   `-- app.css                # Global styles + Tailwind imports
|   |-- lang/
|   |   `-- ur.json                # Urdu server-side translations
|   `-- views/
|       `-- app.blade.php          # Root Blade template (React mount)
|
|-- routes/
|   |-- web.php                    # All HTTP routes (1241 lines)
|   |-- auth.php                   # Authentication routes
|   `-- console.php                # Scheduled tasks
|
|-- config/
|   |-- app.php                    # Application config
|   |-- auth.php                   # Auth guards
|   |-- cache.php                  # Cache configuration
|   |-- database.php               # DB connections
|   |-- logging.php                # Log channels
|   |-- mail.php                   # Mail settings
|   |-- permission.php             # Spatie permissions config
|   |-- queue.php                  # Queue configuration
|   |-- scout.php                  # Typesense search config
|   `-- services.php               # External service keys
|
|-- "Clip matter py  embeding"/    # === PYTHON MICROSERVICE ===
|   |-- embeddings_test.py         # Main FastAPI app (6700+ lines)
|   |-- requirements.txt           # Python dependencies
|   |-- Dockerfile                 # Container definition
|   |-- railway.toml               # Railway platform config
|   |-- Procfile                   # Process manager definition
|   |-- start.sh                   # Startup script
|   |-- migrate_to_3072.py         # 384-dim to 3072-dim migration util
|   |-- test_federalism.py         # Test script
|   |-- test_intent.py             # Test script
|   `-- README.md                  # Python service documentation
|
|-- public/                        # Web server document root
|   `-- index.php                  # Laravel entry point
|-- storage/                       # File storage and logs
|-- tests/                         # PHPUnit test suite
|-- bootstrap/                     # Laravel bootstrap files
|-- vendor/                        # Composer packages
|-- node_modules/                  # NPM packages
|
|-- PROJECT_DOCUMENTATION.md       # THIS FILE
|-- DROPBOX_WEBHOOK_SETUP.md       # Webhook integration guide
|-- SEMANTIC_SEARCH_IMPROVEMENTS.md # Search relevance improvements
|-- SEARCH_FIX_SUMMARY.md          # Search bug fixes log
|-- DUPLICATION_FIX_SUMMARY.md     # Deduplication fixes
|-- LOCALIZATION_GUIDE.md          # i18n implementation guide
|-- clip-matters-*.json            # Google Cloud service account keys
`-- fix-permissions.sh             # Shell script for file permissions
```

---

## 5. Database Schema & Models

### 5.1 `users` Table
**Model:** [`User.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/User.php)

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Primary key |
| `name` | string | Full name |
| `email` | string unique | Login email |
| `phone` | string | Phone number |
| `profile_picture` | string | Profile image path |
| `password` | string | Bcrypt hashed |
| `status` | string | active / inactive |
| `can_edit_profile` | boolean | Self-edit permission |
| `email_notifications` | boolean | Email preference |
| `created_by` | FK users | Who created this user |
| `dropbox_access_token` | text | Dropbox OAuth token |
| `dropbox_refresh_token` | text | Dropbox refresh token |
| `dropbox_token_expires_at` | datetime | Token expiry time |
| `dropbox_team_id` | string | Dropbox Business team ID |
| `dropbox_team_member_id` | string | Dropbox Business member ID |
| `dropbox_root_namespace_id` | string | Dropbox namespace for team |
| `dropbox_cursor` | text | Delta sync cursor |
| `google_access_token` | text | YouTube OAuth token |
| `google_refresh_token` | text | YouTube refresh token |
| `google_token_expires_at` | datetime | YouTube token expiry |
| `assemblyai_api_key` | string | Per-user AssemblyAI key |

**Relationships:** hasMany(Video), hasMany(VoiceSample), belongsTo(User 'created_by')

**Key methods:**
- `getDropboxCredentialOwner()` — returns SuperAdmin for all non-superAdmin roles
- `getGoogleCredentialOwner()` — returns admin/self based on role hierarchy
- `refreshDropboxToken()` / `refreshGoogleToken()` — auto token refresh
- `isDropboxConnected()` / `isGoogleConnected()` — connection status check
- `getConnectionStatus()` — returns full connection info for UI

---

### 5.2 `videos` Table
**Model:** [`Video.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/Video.php)

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Primary key |
| `user_id` | FK users | Owner |
| `dropbox_path` | string unique | Path in Dropbox (unique constraint!) |
| `filename` | string | Original filename |
| `extension` | string | File extension (mp4, mov etc.) |
| `size_mb` | decimal(8,2) | File size in MB |
| `title` | string | Video title (YouTube compatible, max 100 chars) |
| `description` | text | Video description |
| `processing_status` | string | pending / processing / completed / failed |
| `processing_error` | text | Error message if failed |
| `youtube_video_id` | string | YouTube video ID |
| `youtube_url` | string | Full YouTube URL |
| `youtube_privacy` | string | unlisted / public / private |
| `transcript_id` | string | AssemblyAI transcript job ID |
| `transcript_text` | longtext | Raw full transcript |
| `language_detected` | string | ISO language code |
| `audio_duration_seconds` | integer | Audio length |
| `confidence` | decimal(5,4) | Transcription confidence 0-1 |
| `speakers_count` | integer | Number of distinct speakers |
| `speakers_data` | JSON | Speaker-tagged segments (MAIN DATA for embedding) |
| `transcript_urdu` | JSON | Urdu translated segments |
| `transcript_english` | JSON | English translated segments |
| `summary` | text | AI-generated summary |
| `summary_urdu` | text | Urdu summary |
| `summary_english` | text | English summary |
| `diarization_data` | JSON | Raw Pyannote diarization output |
| `identification_data` | JSON | Speaker identification with named speakers |
| `speaker_mapping` | JSON | AssemblyAI letter -> name mapping |
| `pyannote_job_id` | string | Pyannote async job ID |
| `pyannote_status` | string | Pyannote job status |
| `pyannote_error` | text | Pyannote error details |
| `processing_started_at` | datetime | Pipeline start time |
| `processing_completed_at` | datetime | Pipeline end time |
| `video_created_at` | datetime | Dropbox file creation date |
| `approval_status` | string | pending / approved / rejected |
| `approved_by` | FK users | Admin who approved/rejected |
| `approved_at` | datetime | Approval/rejection time |
| `is_archived` | boolean | Archived flag |
| `archived_by` | FK users | Who archived |
| `archived_at` | datetime | Archive time |
| `rejection_reason` | text | Why video was rejected |
| `has_audio` | boolean | Whether video has audio track |

**Traits:** `Searchable` (Laravel Scout -> Typesense)

**Key Scopes:**
- `scopeReadyForSearch()` — completed (or failed+no audio) + approved + not archived
- `scopeNotArchived()` / `scopeArchived()`
- `scopeApprovalStatus($status)`

**Key Methods:**
- `markAsProcessing()` / `markAsCompleted()` / `markAsFailed($error)`
- `approve($userId)` / `reject($userId, $reason)` / `archive($userId)` / `unarchive()`
- `shouldBeSearchable()` — controls Typesense indexing
- `toSearchableArray()` — defines what goes into Typesense index

---

### 5.3 `video_embeddings` Table
**Model:** [`VideoEmbedding.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/VideoEmbedding.php)

One-to-one with videos. Tracks Qdrant embedding status.

| Column | Type | Description |
|--------|------|-------------|
| `video_id` | FK videos unique | One-to-one link |
| `qdrant_collection` | string | Collection name (video_transcript_segments) |
| `segments_count` | integer | Number of vectors in Qdrant |
| `vector_dimensions` | integer | 3072 for OpenAI, 384 for FastEmbed |
| `status` | string | pending / processing / completed / failed |
| `error_message` | text | Failure reason |
| `started_at` | datetime | Embedding start |
| `completed_at` | datetime | Embedding completion |
| `failed_at` | datetime | Failure time |

---

### 5.4 `voice_samples` Table
**Model:** [`VoiceSample.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/VoiceSample.php)

Stores speaker voice biometric samples for speaker identification.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Primary key |
| `user_id` | FK users | Who uploaded |
| `name` | string | Speaker name (e.g., "Imran Khan") |
| `file_path` | string | Path to audio file in storage |
| `voiceprint` | text | Base64 encoded Pyannote voiceprint |
| `duration` | integer | Sample duration in seconds |
| `language` | string | Language of sample |

---

### 5.5 `video_tags` Table
**Model:** [`VideoTag.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/VideoTag.php)

Manual tags assigned by admins to videos.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Primary key |
| `video_id` | FK videos | Tagged video |
| `tag` | string | Tag text (e.g., "imran-khan", "pti") |

---

### 5.6 `search_logs` Table
**Model:** [`SearchLog.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/SearchLog.php)

Analytics log for all searches performed.

| Column | Type | Description |
|--------|------|-------------|
| `user_id` | FK users | Who searched |
| `query` | text | Full search query text |
| `word` | string | Single-word searches |
| `speaker` | string | Speaker filter used |
| `video_id` | FK videos | Specific video if searched |
| `results_count` | integer | Segments returned |
| `videos_count` | integer | Unique videos in results |
| `min_score` | float | Minimum relevance score |
| `response_time_ms` | integer | Search latency in ms |
| `search_type` | string | semantic / keyword / hybrid |
| `filters` | JSON | Applied filter parameters |

---

### 5.7 `dropbox_deletions` Table
**Model:** [`DropboxDeletion.php`](file:///home/master/applications/ptfmnnpjhn/public_html/app/Models/DropboxDeletion.php)

Tracks files deleted from Dropbox to sync deletions.

| Column | Type | Description |
|--------|------|-------------|
| `dropbox_path` | string | Deleted file path |
| `video_id` | FK videos nullable | Matched video if found |
| `processed` | boolean | Whether deletion was handled |

---

### 5.8 Spatie Permission Tables
Standard tables: `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`

---

## 6. User Roles & Permissions

The system has **4 roles** managed by Spatie Laravel Permission package.

| Role | Admin Panel | Video Mgmt | User Creation | Dropbox | YouTube |
|------|------------|------------|---------------|---------|---------|
| `superAdmin` | Full access | Full | admin, manager, user | Own account | Own account |
| `admin` | Full access | Full | manager, user | Inherited from superAdmin | Own account |
| `manager` | Full access | Full view | user only | Inherited from superAdmin | From admin creator |
| `user` | NO (search only) | None | None | — | — |

### Credential Inheritance
**Dropbox:** ALL roles use the SuperAdmin's Dropbox account. Managed via `User::getDropboxCredentialOwner()`.

**Google/YouTube:**
- `superAdmin` → own credentials
- `admin` → own credentials
- `manager` → their admin creator's credentials
- `user` → follows creator chain upward

### Route Access Control
- `/` (root) → Redirects based on role (admin to dashboard, user to search page)
- `/admin/*` → Requires `role:admin|superAdmin|manager`
- `/admin/settings` → Requires `role:superAdmin` only
- `/user/*` → Any authenticated user

---

## 7. Backend — Controllers

### VideoController.php (3,863 lines)
The largest file in the project. Handles ALL video-related operations.
**File:** [VideoController.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Http/Controllers/VideoController.php)

Key responsibilities:
- List and show videos to admins
- Trigger Dropbox sync and video processing
- Semantic search (calls Python service + Typesense)
- Dashboard analytics stats
- Transcript export to PDF
- Speaker tagging and re-tagging

---

### DropboxWebhookController.php (640 lines)
Handles Dropbox real-time file change notifications.
**File:** [DropboxWebhookController.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Http/Controllers/DropboxWebhookController.php)

| Method | Route | Description |
|--------|-------|-------------|
| `handleWebhook()` | GET/POST /webhook/dropbox | Route dispatcher |
| `verify()` | GET | Challenge echo for Dropbox verification |
| `webhook()` | POST | Handles file change notifications |

**Security:** Validates `X-Dropbox-Signature` HMAC-SHA256 header.

---

### Admin/VideoApprovalController.php (646 lines)
**File:** [VideoApprovalController.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Http/Controllers/Admin/VideoApprovalController.php)

| Method | Description |
|--------|-------------|
| `list()` | Paginated video list with tab filtering |
| `approve($video)` | Set approval_status = approved |
| `reject($video)` | Set approval_status = rejected with reason |
| `archive($video)` | Set is_archived = true |
| `unarchive($video)` | Set is_archived = false |
| `bulkApprove()` | Batch approve multiple videos |
| `bulkReject()` | Batch reject with reason |
| `saveTags()` | Save/update video tags |
| `updateAudioStatus()` | Toggle has_audio flag |
| `getPreviewLink()` | Generate temporary Dropbox preview URL |

---

### Admin/UserController.php (296 lines)
**File:** [UserController.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Http/Controllers/Admin/UserController.php)

Enforces role hierarchy for user creation:
- superAdmin can create any role
- admin can create manager and user
- manager can only create user

---

### Admin/VoiceSampleController.php (406 lines)
**File:** [VoiceSampleController.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Http/Controllers/Admin/VoiceSampleController.php)

Manages speaker voiceprint samples:
- Upload audio files (.mp3, .wav, .ogg, max 10MB)
- Extract duration via FFprobe
- Call Pyannote API to extract voiceprint
- Store in voice_samples table for use in speaker identification

---

### YoutubeController.php (935 lines)
Handles YouTube-specific operations including OAuth flow and channel management.

---

## 8. Backend — Services

### DropboxService.php (972 lines)
**File:** [DropboxService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/DropboxService.php)

Full Dropbox API wrapper with Business (Team) account support.

Key features:
- Auto-detects Dropbox Business accounts
- Sends `Dropbox-API-Select-User` and `Dropbox-API-Path-Root` headers for team contexts
- Streaming download for large video files (avoids memory issues)
- Cursor-based delta sync for efficient change detection

Key methods: `getAuthUrl()`, `handleCallback()`, `listFolder()`, `listFolderContinue()`, `downloadToFile()`, `fileExists()`, `getTemporaryLink()`, `detectTeamAccount()`

---

### AssemblyAiService.php (538 lines)
**File:** [AssemblyAiService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/AssemblyAiService.php)

Handles all transcription and speaker identification.

API key priority: `user.assemblyai_api_key` → fallback to `ASSEMBLYAI_API_KEY` env var.

Key methods: `uploadAudio()`, `transcribe()`, `getTranscript()`, `identifySpeakers()`, `getVoiceprint()`

Note: Speaker identification calls Pyannote (not AssemblyAI directly) — sends voiceprints from `voice_samples` table.

---

### VideoProcessingService.php (447 lines)
**File:** [VideoProcessingService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/VideoProcessingService.php)

Orchestrates video processing initiation:
1. Validates user has Dropbox/Google connections
2. Verifies file exists in Dropbox
3. Checks for duplicates (globally for admin roles, per-user for users)
4. Creates/updates Video record
5. Dispatches ProcessVideoJob to queue

---

### GoogleApiService.php (248 lines)
**File:** [GoogleApiService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/GoogleApiService.php)

YouTube OAuth and upload management. Resolves credential owner based on role hierarchy.

Key methods: `getAuthUrl()`, `handleCallback()`, `uploadToYouTube()`, `getAccessToken()` (auto-refreshes)

---

### SpeakerTaggingService.php (341 lines)
**File:** [SpeakerTaggingService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/SpeakerTaggingService.php)

Merges AssemblyAI speaker labels (A, B, C) with Pyannote identified names.

Algorithm:
1. For each AssemblyAI utterance (timestamps in milliseconds), find the midpoint
2. Find overlapping Pyannote segment (timestamps in seconds)
3. Use voting system across all utterances to create stable A->Name mapping
4. Apply mapping to all utterances
5. Result: `speakers_data` JSON with actual speaker names

---

### SearchNormalizationService.php (87 lines)
**File:** [SearchNormalizationService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/SearchNormalizationService.php)

Text normalization for search matching:
- `normalizeUrdu()`: Arabic->Urdu character standardization, diacritic removal
- `normalizeEnglishAndRoman()`: Lowercase, punctuation removal, Roman Urdu aliases

---

### SearchSuggestionService.php (185 lines)
**File:** [SearchSuggestionService.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Services/SearchSuggestionService.php)

Provides autocomplete suggestions from 3 sources (combined, deduplicated, sorted):
1. Database `video_tags` table (highest priority)
2. Typesense `search_phrases` collection
3. Typesense video titles

Returns max 8 suggestions sorted by match quality.

---

## 9. Jobs (Background Processing)

All jobs use Redis queue (`QUEUE_CONNECTION=redis`).

### ProcessVideoJob.php (1,926 lines) — CORE PIPELINE
**File:** [ProcessVideoJob.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Jobs/ProcessVideoJob.php)

Configuration: timeout=3600s (1 hour), tries=2, backoff=30s

**10-Step Pipeline:**

| Step | Action | Description |
|------|--------|-------------|
| 1 | Download | Streaming download from Dropbox to `storage/app/temp/` |
| 2 | Extract Audio | FFmpeg extracts audio track |
| 3 | Upload Audio | Upload to AssemblyAI CDN |
| 4 | Transcribe | AssemblyAI speech-to-text with speaker_labels=true (polling) |
| 5 | Speaker ID | Pyannote identifies named speakers using voiceprints from DB |
| 6 | Tag Speakers | SpeakerTaggingService maps A/B/C -> actual names |
| 7 | YouTube Upload | GoogleApiService uploads video file |
| 8 | Translate | OpenAI GPT-4 translates transcript to Urdu and English |
| 9 | Summarize | OpenAI generates summary in Urdu and English |
| 10 | Complete | Mark video completed, cleanup temp files, send email |

After completion: Admin approves -> `GenerateVideoEmbedding` job dispatched.

---

### GenerateVideoEmbedding.php (359 lines)
**File:** [GenerateVideoEmbedding.php](file:///home/master/applications/ptfmnnpjhn/public_html/app/Jobs/GenerateVideoEmbedding.php)

Queue: `high` (priority), timeout=3600s, tries=3, backoff=[30,60,120]s

Process:
1. Parse `speakers_data` JSON from video record
2. Call Python service `/embed-video` endpoint
3. Python service: chunk text -> OpenAI embeddings -> store in Qdrant
4. Update `video_embeddings` record status

---

### ExtractVideoPhrasesJob.php
Triggered automatically when video becomes searchable (via `Video::booted()` observer).
Calls `SearchPhraseExtractor` to index key phrases in Typesense `search_phrases` collection.

---

## 10. Artisan Commands

```bash
# === EMBEDDING COMMANDS ===

# Re-embed a single video
php artisan embeddings:reembed --video=123

# Re-embed all videos (synchronously via Python service)
php artisan embeddings:reembed-all [--failed-only] [--force] [--dry-run] [--delay=3]

# Batch embed all approved+completed videos
php artisan embeddings:embed-approved

# Full regeneration (destroys and recreates Qdrant collection)
php artisan embeddings:regenerate-all

# Update Qdrant metadata without re-embedding vectors
php artisan embeddings:update-payloads

# Clean up failed embedding records
php artisan embeddings:cleanup-failed

# === VIDEO COMMANDS ===

# Audit video data integrity
php artisan videos:audit

# Find and remove duplicate video records
php artisan videos:cleanup-duplicates

# Fix malformed transcript chunks
php artisan videos:fix-chunks

# Repair English translation data
php artisan videos:fix-english

# === SEARCH COMMANDS ===

# Rebuild Typesense search phrase index
php artisan search:extract-phrases

# === QDRANT COMMANDS ===

# Remove orphaned Qdrant vectors
php artisan qdrant:cleanup

# === VOICE COMMANDS ===

# Update voice sample duration fields
php artisan voices:update-durations

# === DEVELOPMENT ===

# Run full dev environment (Laravel + Queue + Vite)
composer run dev

# Run tests
php artisan test
```

---

## 11. Frontend — React Pages & Components

All frontend code is in `resources/js/`. Built with Vite and served via Laravel Inertia.js.

### 11.1 Admin Pages

| Page | File | Key Features |
|------|------|-------------|
| Dashboard | [Dashboard.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/AdminSide/Dashboard.jsx) | Highcharts analytics, search stats, video counts |
| Video Management | [VideoManagement/index.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/AdminSide/VideoManagement/index.jsx) | Tab-based video list, process trigger, Dropbox sync |
| Video Approval | [VideoApproval/index.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/AdminSide/VideoApproval/index.jsx) | Approve/reject/archive workflow, bulk actions |
| User Management | [UserManagement.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/AdminSide/UserManagement.jsx) | CRUD users with role assignment |
| Voice Samples | [VoiceSample.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/AdminSide/VoiceSample.jsx) | Upload/manage speaker voiceprints |
| Settings | [Settings.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/AdminSide/Settings.jsx) | Email notification settings (superAdmin) |

### 11.2 User Pages

| Page | File | Key Features |
|------|------|-------------|
| Search Landing | [SearchPage.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/UserSide/SearchPage.jsx) | Voice input, autocomplete, language switching |
| Search Results | [SearchedPage.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/UserSide/SearchedPage.jsx) | Result cards with timestamps, speaker info, score display |
| Video Player | [MainVideoPage/index.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Pages/UserSide/MainVideoPage/index.jsx) | YouTube embedded player + full transcript viewer |

### 11.3 Components

| Component | Description |
|-----------|-------------|
| [Sidebar.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Components/Sidebar.jsx) | Admin navigation sidebar with role-based links |
| [LoadingOverlay.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Components/LoadingOverlay.jsx) | Full-screen loading state with spinner |
| [TagsModal.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Components/TagsModal.jsx) | Video tag management modal |
| [LanguageSwitcher.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Components/LanguageSwitcher.jsx) | Urdu/English toggle with RTL support |
| [Modal.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Components/Modal.jsx) | Generic modal wrapper |
| [Dropdown.jsx](file:///home/master/applications/ptfmnnpjhn/public_html/resources/js/Components/Dropdown.jsx) | Reusable dropdown menu |

### 11.4 Internationalization

- **Languages:** English (default) and Urdu (RTL)
- **Library:** i18next + react-i18next
- **Detection:** Browser language auto-detection
- **Files:** `resources/js/locales/` for frontend, `resources/lang/ur.json` for server-side
- **Guide:** [LOCALIZATION_GUIDE.md](file:///home/master/applications/ptfmnnpjhn/public_html/LOCALIZATION_GUIDE.md)

---

## 12. Python Semantic Search Service

**Location:** `Clip matter py  embeding/embeddings_test.py` (~6,700 lines)
**Deployed at:** `https://clip-matter-service.up.railway.app`
**Framework:** FastAPI + Uvicorn on Railway

### 12.1 API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/embed-video` | Embed video transcript segments into Qdrant |
| POST | `/search` | Main semantic search |
| POST | `/search-by-title` | Search by video title |
| POST | `/suggest` | Autocomplete suggestions |
| GET | `/video/{id}/segments` | Get all Qdrant segments for a video |
| DELETE | `/video/{id}/embeddings` | Delete all embeddings for a video |
| GET | `/health` | Health check (no auth required) |
| GET | `/stats` | Qdrant collection statistics |

### 12.2 Search Algorithm (5 Stages)

```
Query Input
    |
    v
STAGE 1: QUERY EXPANSION (GPT-3.5-turbo)
   Expand with synonyms in Urdu, English, Roman Urdu
   "imran" becomes ["imran khan", "عمران خان", "PTI chief"]
    |
    v
STAGE 2: EMBEDDING (OpenAI text-embedding-3-large)
   Convert expanded queries to 3072-dimensional vectors
    |
    v
STAGE 3: VECTOR SEARCH (Qdrant HNSW)
   Search video_transcript_segments collection
   ef=128, retrieval threshold=0.35
    |
    v
STAGE 4: FUZZY MATCHING + LLM RERANKING (GPT-4o-mini)
   RapidFuzz for typo tolerance
   Strict LLM scoring: 60% weight, filters score < 0.3
    |
    v
STAGE 5: RELEVANCE VALIDATION
   GPT-4o-mini checks if results actually match query
   Returns empty set with explanation for off-topic queries
   (e.g., "bill gates" in Pakistan politics DB returns 0 results)
```

### 12.3 Qdrant Collection Schema

**Collection:** `video_transcript_segments`
**Vector:** 3072 dimensions, Cosine distance

Each point payload:
```json
{
  "video_id": 123,
  "text": "Speaker utterance text",
  "speaker": "Imran Khan",
  "start": 10.5,
  "end": 25.3,
  "language": "ur",
  "segment_index": 0
}
```

### 12.4 Deployment (Railway)

```bash
# Push to GitHub -> Railway auto-deploys via Dockerfile

# Environment variables needed in Railway dashboard:
QDRANT_URL=https://your-instance.qdrant.io:6333
QDRANT_API_KEY=your-qdrant-key
OPENAI_API_KEY=sk-...
USE_OPENAI_EMBEDDINGS=true
OPENAI_EMBEDDING_MODEL=text-embedding-3-large
EMBEDDING_DIMENSION=3072
USE_QUERY_EXPANSION=true
USE_RERANKING=true
API_KEY=your-service-api-key
```

---

## 13. Video Processing Pipeline

Full lifecycle from Dropbox file to searchable content:

```
STEP 1: DETECTION
  Dropbox Webhook -> POST /webhook/dropbox
  OR Admin manually triggers sync from VideoManagement page

STEP 2: INITIATION
  VideoProcessingService::processVideo()
  - Check for duplicate by dropbox_path
  - Create Video record (status: pending)
  - Dispatch ProcessVideoJob to Redis queue

STEP 3: BACKGROUND PROCESSING (ProcessVideoJob)
  3.1  Download from Dropbox (streaming, supports 500MB+)
  3.2  Extract audio with FFmpeg
  3.3  Upload audio to AssemblyAI CDN
  3.4  Transcribe with speaker diarization (poll until complete)
  3.5  Identify speakers with Pyannote (using voiceprints from DB)
  3.6  Tag speakers (SpeakerTaggingService merges A/B/C -> names)
  3.7  Upload video to YouTube (unlisted by default)
  3.8  Translate transcript (OpenAI GPT-4 -> Urdu + English)
  3.9  Generate summaries (Urdu + English)
  3.10 Mark completed, cleanup temp files, email notification

STEP 4: ADMIN APPROVAL
  Video appears in VideoApproval -> Pending tab
  Admin reviews, previews from Dropbox temporary link
  Admin approves / rejects (with reason) / archives

STEP 5: POST-APPROVAL (on approve)
  - ExtractVideoPhrasesJob -> indexes phrases in Typesense
  - GenerateVideoEmbedding -> calls Python service -> Qdrant vectors

STEP 6: SEARCHABLE
  Full-text search: Typesense (title, description, transcripts, tags)
  Semantic search: Python service -> Qdrant (speaker segments)
```

---

## 14. Search System Architecture

Two search engines work in parallel and results are merged in the frontend.

### 14.1 Full-Text Search (Typesense)

**Purpose:** Keyword search, exact phrase matching, autocomplete
**Integration:** Laravel Scout (`use Searchable` trait on Video model)

Indexed fields in Typesense:
- `title`, `description`
- `transcript_urdu`, `transcript_english`
- `summary_urdu`, `summary_english`
- `normalized_title` (SearchNormalizationService processed)
- `normalized_urdu` (Arabic->Urdu normalized)
- `manual_tags` (array)

Only `shouldBeSearchable()` videos are indexed (completed + approved + not archived).

### 14.2 Semantic Search (Qdrant + Python)

**Purpose:** Meaning-based search, cross-language understanding
**Flow:** Laravel VideoController -> Python service /search -> Qdrant -> results

Search modes available in frontend (`SearchedPage.jsx`):
- `semantic` — vector search only
- `keyword` — Typesense only
- `hybrid` — both engines, merged and deduplicated

### 14.3 Search Analytics

Every search logged to `search_logs` table. Admin dashboard shows:
- Searches per day (Highcharts line chart)
- Most searched keywords (leaderboard)
- Average response time
- Total search count

---

## 15. Dropbox Integration

### 15.1 OAuth2 Flow

```
/connect/dropbox -> DropboxService::getAuthUrl()
  -> Dropbox consent page (team scopes for Business)
  -> /dropbox/callback -> handleCallback(code)
  -> Save tokens + team IDs to users table
```

**Required Scopes:**
`account_info.read`, `files.metadata.read/write`, `files.content.read/write`,
`sharing.read`, `team_info.read`, `team_data.member`, `team_data.content.read`,
`files.team_metadata.read`

### 15.2 Business Account Support

Auto-detects Business accounts:
- `DropboxService::detectTeamAccount()` fetches `team_member_id` and `root_namespace_id`
- All subsequent API calls include:
  - `Dropbox-API-Select-User: {team_member_id}`
  - `Dropbox-API-Path-Root: {root_namespace_id}`

### 15.3 Webhook Flow

```
Dropbox -> POST /webhook/dropbox
  -> Validate X-Dropbox-Signature (HMAC-SHA256 with DROPBOX_APP_SECRET)
  -> Extract account IDs from list_folder.accounts
  -> Find SuperAdmin user
  -> listFolderContinue(cursor) to get changes
  -> Process new/modified video files (dispatch ProcessVideoJob)
  -> Track deletions in dropbox_deletions table
  -> Update dropbox_cursor for next delta
```

**Webhook URL:** `https://clip.digitalmatters.pk/webhook/dropbox`

### 15.4 Delta Sync

- First sync: `listFolder('')` — enumerate all files
- Subsequent syncs: `listFolderContinue(cursor)` — only changes
- Processed extensions: `mp4`, `mov`, `avi`, `mkv`, `webm`

---

## 16. YouTube/Google Integration

### 16.1 OAuth2 Flow

```
/connect/youtube -> GoogleApiService::getAuthUrl()
  -> Google OAuth2 consent (YOUTUBE_UPLOAD scope)
  -> /auth/google/callback -> handleCallback(code)
  -> Save tokens to users table
```

Only `superAdmin` and `admin` can connect their own YouTube.
`manager` role inherits from their admin creator.

### 16.2 Token Auto-Refresh

`User::refreshGoogleToken()` auto-refreshes before expiry.
`GoogleApiService::getAccessToken()` calls refresh when needed.

### 16.3 Video Upload

Called inside `ProcessVideoJob` Step 7:
```php
$googleService->uploadToYouTube(
    filePath: $localVideoPath,
    title: $videoTitle,     // max 100 chars, no < > | characters
    description: $videoDescription,
    privacy: 'unlisted'
);
// Returns youtube_video_id stored in videos table
```

---

## 17. API Routes Reference

**Main route file:** [web.php](file:///home/master/applications/ptfmnnpjhn/public_html/routes/web.php) (1,241 lines)

### Public Routes (no auth)
```
GET|POST /webhook/dropbox       -> Dropbox webhook
GET      /api/search-suggestions -> Autocomplete suggestions
POST     /api/qdrant-webhook    -> Qdrant callback
```

### Auth Routes (Laravel Breeze)
```
GET  /login, POST /login
GET  /register, POST /register
POST /logout
GET/POST /forgot-password
GET/POST /reset-password/{token}
GET  /verify-email
```

### Admin Routes (auth + role:admin|superAdmin|manager)
```
GET  /admin/dashboard                           -> Dashboard
GET  /admin/dashboard/stats                     -> Analytics JSON
GET  /admin/video-management                    -> Video management page
GET  /admin/video-approval                      -> Approval page
GET  /admin/video-approval/list                 -> Video list (tabbed)
POST /admin/video-approval/{video}/approve
POST /admin/video-approval/{video}/reject
POST /admin/video-approval/{video}/archive
POST /admin/video-approval/{video}/unarchive
POST /admin/video-approval/bulk-approve
POST /admin/video-approval/bulk-reject
POST /admin/video-approval/bulk-archive
POST /admin/video-approval/bulk-unarchive
POST /admin/video-approval/{video}/audio-status
GET  /admin/video-approval/{video}/tags
POST /admin/video-approval/{video}/tags
GET  /admin/user-management
GET  /admin/user-management/users/list
POST /admin/user-management/users
PUT  /admin/user-management/users/{id}
DEL  /admin/user-management/users/{id}
GET  /admin/voice-sample
GET  /admin/voice-sample/get-voice-samples
POST /admin/voice-sample/upload-voice-sample    (admin|superAdmin|manager)
DEL  /admin/voice-sample/delete-voice-sample/{id}
GET  /admin/settings                            (superAdmin only)
GET  /admin/settings/get
POST /admin/settings/email-notifications
```

### Video Processing Routes (auth)
```
GET  /videos                    -> Paginated video list
POST /sync-videos               -> Trigger Dropbox sync
POST /process-video             -> Start video processing
GET  /dropbox/files             -> List Dropbox directory
POST /search                    -> Semantic search
POST /search-by-title           -> Title search
```

### User Routes (auth)
```
GET  /user/search-page          -> Search landing
GET|POST /user/searched-items   -> Search results page
GET  /user/profile
PATCH /user/profile
```

### Connection Routes (auth)
```
GET /connect/dropbox            -> Start Dropbox OAuth
GET /dropbox/callback           -> Dropbox OAuth callback
GET /connect/youtube            -> Start YouTube OAuth
GET /auth/google/callback       -> Google OAuth callback
```

---

## 18. Environment Variables

**Template:** [.env.example](file:///home/master/applications/ptfmnnpjhn/public_html/.env.example)

```env
# Application
APP_NAME=Clip-Matters
APP_ENV=local|production
APP_KEY=base64:...
APP_DEBUG=true|false
APP_URL=https://clip.digitalmatters.pk
APP_LOCALE=en

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ptfmnnpjhn
DB_USERNAME=ptfmnnpjhn
DB_PASSWORD=...

# Queue (MUST be redis for background jobs)
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Cache
CACHE_STORE=database

# Email (Mailgun)
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=...
MAILGUN_SECRET=...
MAIL_FROM_ADDRESS=no-reply@...

# Dropbox OAuth
DROPBOX_APP_KEY=vy9egv92vhbl1j4
DROPBOX_APP_SECRET=cmltw9ni2wcms4s
DROPBOX_REDIRECT_URI=https://clip.digitalmatters.pk/dropbox/callback

# Google/YouTube OAuth
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://clip.digitalmatters.pk/auth/google/callback
YOUTUBE_API_KEY=...
GOOGLE_CLOUD_PROJECT_ID=clip-matters
GOOGLE_CLOUD_LOCATION=global
GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json

# AI/Transcription Services
ASSEMBLYAI_API_KEY=...
PYANNOTE_API_KEY=...
OPENAI_API_KEY=sk-...

# Python Embedding Service
PYTHON_EMBEDDING_SERVICE_URL=https://clip-matter-service.up.railway.app

# Typesense
TYPESENSE_API_KEY=...
TYPESENSE_HOST=localhost
TYPESENSE_PORT=8108
```

---

## 19. Deployment & Infrastructure

### Laravel Application

**Server:** Cloudways (Apache + PHP-FPM)
**Document Root:** `/home/master/applications/ptfmnnpjhn/public_html/public/`

**Setup steps:**
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm install
npm run build
```

**Queue Worker (MUST be running continuously):**
```bash
# Development
php artisan queue:listen --tries=1 --timeout=3600

# Production (Supervisor recommended)
php artisan queue:work redis --tries=2 --timeout=3600 --queue=high,default
```

**Fix file permissions if needed:**
```bash
bash fix-permissions.sh
```

### Python Service (Railway)

1. Push `Clip matter py  embeding/` to a separate GitHub repo (or subdirectory)
2. Create Railway project from that repo
3. Railway auto-detects the Dockerfile
4. Set environment variables in Railway dashboard
5. Deploy — Railway builds and runs automatically

**Health check:**
```bash
curl https://clip-matter-service.up.railway.app/health
```

**Test search:**
```bash
curl -X POST https://clip-matter-service.up.railway.app/search \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"query": "imran khan", "top_k": 10}'
```

### Required External Setup

1. **Typesense** — Must be running (self-hosted or cloud). Configure in `config/scout.php`
2. **Redis** — Required for queue jobs. Must be running on port 6379
3. **MySQL** — Primary database. Run `php artisan migrate`
4. **Dropbox App** — Register webhook at: `https://www.dropbox.com/developers/apps`
5. **Google Cloud** — Service account JSON needed for legacy speech features

---

## 20. Known Issues & Fixes Applied

### Video Deduplication Fix
**Problem:** Same Dropbox video was being processed multiple times when multiple sync triggers fired simultaneously.
**Root Cause:** No database-level uniqueness constraint on `dropbox_path`.
**Fix:** Added `UNIQUE` constraint on `videos.dropbox_path` column + app-level pre-check before dispatching jobs.
**Doc:** [DUPLICATION_FIX_SUMMARY.md](file:///home/master/applications/ptfmnnpjhn/public_html/DUPLICATION_FIX_SUMMARY.md)

---

### Search False Positives
**Problem:** Querying "bill gates" returned Pakistan politics videos with 85%+ match scores.
**Root Cause:** Vector search always returns "closest" match even when completely unrelated. LLM reranking was inflating scores for irrelevant results.
**Fix:** Added `validate_query_relevance()` in Python service. Uses GPT-4o-mini to check if any results actually match the query before returning them.
**Doc:** [SEMANTIC_SEARCH_IMPROVEMENTS.md](file:///home/master/applications/ptfmnnpjhn/public_html/SEMANTIC_SEARCH_IMPROVEMENTS.md)

---

### Urdu Search Normalization
**Problem:** Urdu searches failing because Arabic character variants (ي, ى, ك) were not matching Urdu (ی, ک).
**Fix:** `SearchNormalizationService` applied to both indexed content and search queries at index time and query time.
**Doc:** [SEARCH_FIX_SUMMARY.md](file:///home/master/applications/ptfmnnpjhn/public_html/SEARCH_FIX_SUMMARY.md)

---

### Embedding Dimension Migration
**Problem:** Old embeddings used 384-dim FastEmbed vectors, new system uses 3072-dim OpenAI vectors (incompatible).
**Fix:**
1. `migrate_to_3072.py` utility to delete old Qdrant collection and recreate with new schema
2. `php artisan embeddings:reembed-all` to re-embed all videos with new model

---

### Dropbox Business Account Headers
**Problem:** Dropbox Business (Team) accounts require special headers for all API calls, causing 409 errors.
**Fix:** `DropboxService::detectTeamAccount()` auto-detects business account on first use and stores `team_member_id` + `root_namespace_id` in the users table. All subsequent calls include the required headers.

---

### Silent/No-Audio Videos
**Problem:** Videos without audio tracks were failing in the transcription step, causing the entire pipeline to fail and leaving videos in "failed" state unnecessarily.
**Fix:** Added `has_audio` boolean column on videos table. FFmpeg probe checks for audio before attempting transcription. Videos with `has_audio=false` are treated as successfully processed (they can still be indexed by title/description/tags).

---

### Large Video File Download Memory Issue
**Problem:** Loading entire video files (500MB+) into PHP memory caused out-of-memory errors.
**Fix:** `DropboxService::downloadToFile()` uses streaming download — writes chunks directly to disk without loading entire file into memory.

---

*End of Documentation*
*Generated: July 31, 2026 for Clip Matters project team handover*
*For questions about specific components, refer to inline code comments and linked files above.*
