<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video transcripts</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eceef1; color: #222; font: 12pt/1.65 Cambria, Georgia, 'Times New Roman', serif; }
        .toolbar, .pagination { max-width: 900px; margin: 24px auto; padding: 0 20px; font: 14px/1.5 Arial, sans-serif; }
        form { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        input { flex: 1; min-width: 180px; padding: 10px; border: 1px solid #aab2bc; border-radius: 4px; font: inherit; }
        button, .pagination a { padding: 9px 16px; border: 1px solid #29466a; background: #29466a; color: white; border-radius: 4px; font: inherit; text-decoration: none; cursor: pointer; }
        a { color: #29466a; }
        .document { max-width: 900px; margin: 24px auto; padding: 65px 75px; background: white; box-shadow: 0 2px 12px #0002; }
        header { border-bottom: 2px solid #29466a; padding-bottom: 20px; margin-bottom: 30px; }
        h1 { font-size: 26pt; margin: 0; color: #29466a; }
        h2 { font-size: 17pt; margin: 0 0 8px; overflow-wrap: anywhere; }
        h3 { font-size: 12pt; margin: 20px 0 10px; color: #29466a; }
        .meta { color: #606975; font: 10pt/1.5 Arial, sans-serif; }
        .videos { padding-left: 25px; }
        .video { padding: 12px 0 30px 10px; margin-bottom: 25px; border-bottom: 1px solid #d8dde3; }
        .segment { display: grid; grid-template-columns: 145px minmax(0, 1fr); gap: 16px; margin: 0 0 14px; }
        .timestamp { font: 9pt/1.7 Arial, sans-serif; color: #606975; }
        .text { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; unicode-bidi: plaintext; }
        .pagination { display: flex; justify-content: space-between; align-items: center; gap: 15px; }
        @media (max-width: 640px) { .document { padding: 28px 20px; margin: 15px 10px; } .segment { grid-template-columns: 1fr; gap: 2px; } h1 { font-size: 22pt; } }
        @page { size: A4; margin: 20mm; }
        @media print { body { background: white; } .toolbar, .pagination { display: none; } .document { max-width: none; margin: 0; padding: 0; box-shadow: none; } h2, h3 { break-after: avoid; } .segment { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <form method="GET" action="{{ route('transcripts.document') }}">
            <label for="title">Video title</label>
            <input id="title" name="title" type="search" maxlength="255" value="{{ $title }}" placeholder="Search by video title">
            <button type="submit">Search</button>
            @if ($title !== '') <a href="{{ route('transcripts.document') }}">Clear</a> @endif
            <button type="button" onclick="window.print()">Print</button>
        </form>
        @error('title') <p role="alert">{{ $message }}</p> @enderror
    </div>
    <main class="document">
        <header>
            <h1>Video transcripts</h1>
            <p class="meta">{{ number_format($videos->total()) }} videos · 50 per page · Page {{ $videos->currentPage() }} of {{ $videos->lastPage() }}</p>
            @if ($title !== '') <p>Title search: <strong>{{ $title }}</strong></p> @endif
        </header>
        @if ($videos->isEmpty())
            <p>No transcripts found{{ $title !== '' ? ' for this title search' : '' }}.</p>
        @else
            <ol class="videos" start="{{ $videos->firstItem() }}">
                @foreach ($videos as $video)
                    <li class="video">
                        <h2 dir="auto">{{ $video->title ?: 'Untitled video' }}</h2>
                        <div class="meta">Video date: {{ ($video->video_created_at ?? $video->created_at)?->format('d M Y, H:i') ?? 'Unavailable' }}</div>
                        @foreach ($video->document_sections as $section)
                            <h3>{{ $section['language'] }}</h3>
                            @forelse ($section['rows'] as $row)
                                <div class="segment">
                                    <span class="timestamp">{{ $row['timestamp'] ?? 'Timestamp unavailable' }}</span>
                                    <p class="text" dir="auto">{{ $row['text'] }}</p>
                                </div>
                            @empty
                                <p>Transcript text unavailable.</p>
                            @endforelse
                        @endforeach
                    </li>
                @endforeach
            </ol>
        @endif
    </main>
    @if ($videos->hasPages())
        <nav class="pagination" aria-label="Transcript pages">
            @if ($videos->onFirstPage()) <span>Previous</span>
            @else <a rel="prev" href="{{ $videos->previousPageUrl() }}">Previous</a> @endif
            <span>Page {{ $videos->currentPage() }} of {{ $videos->lastPage() }}</span>
            @if ($videos->hasMorePages()) <a rel="next" href="{{ $videos->nextPageUrl() }}">Next</a>
            @else <span>Next</span> @endif
        </nav>
    @endif
</body>
</html>
