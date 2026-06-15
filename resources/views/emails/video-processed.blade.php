<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Processing {{ ucfirst($status) }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #28933F;
            margin: 0;
            font-size: 24px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 10px;
        }
        .status-completed {
            background-color: #28933F;
            color: white;
        }
        .status-failed {
            background-color: #dc3545;
            color: white;
        }
        .content {
            margin: 20px 0;
        }
        .video-info {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .video-info h2 {
            color: #333;
            font-size: 18px;
            margin-top: 0;
        }
        .info-row {
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: bold;
            color: #666;
            display: inline-block;
            width: 150px;
        }
        .info-value {
            color: #333;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background-color: #28933F;
            color: white !important;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            text-align: center;
        }
        .button:hover {
            background-color: #1f7030;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎬 Clip Matters</h1>
            <span class="status-badge status-{{ $status }}">{{ ucfirst($status) }}</span>
        </div>

        <div class="content">
            <p>Hello {{ $userName }},</p>
            
            @if($status === 'completed')
                <p>Great news! Your video has been successfully processed and is now ready to view.</p>
            @else
                <p>Unfortunately, there was an issue processing your video. Please check the details below or contact support.</p>
            @endif

            <div class="video-info">
                <h2>📹 Video Details</h2>
                
                <div class="info-row">
                    <span class="info-label">Video Title:</span>
                    <span class="info-value">{{ $videoTitle }}</span>
                </div>

                <div class="info-row">
                    <span class="info-label">File Path:</span>
                    <span class="info-value">{{ $videoPath }}</span>
                </div>

                @if($status === 'completed')
                    @if($duration)
                    <div class="info-row">
                        <span class="info-label">Duration:</span>
                        <span class="info-value">{{ round($duration / 60, 2) }} minutes</span>
                    </div>
                    @endif

                    @if($language)
                    <div class="info-row">
                        <span class="info-label">Language:</span>
                        <span class="info-value">{{ strtoupper($language) }}</span>
                    </div>
                    @endif

                    @if($transcriptLength > 0)
                    <div class="info-row">
                        <span class="info-label">Transcript Length:</span>
                        <span class="info-value">{{ number_format($transcriptLength) }} characters</span>
                    </div>
                    @endif

                    @if($processingTime)
                    <div class="info-row">
                        <span class="info-label">Processing Time:</span>
                        <span class="info-value">{{ $processingTime }} minutes</span>
                    </div>
                    @endif
                @endif
            </div>

            @if($status === 'completed')
                <div style="text-align: center;">
                    <a href="{{ $videoUrl }}" class="button">View Video Details</a>
                </div>
            @endif

            <p style="margin-top: 30px;">
                @if($status === 'completed')
                    You can now search, view transcripts, generate clips, and explore all the features available for this video.
                @else
                    If you continue to experience issues, please contact our support team.
                @endif
            </p>
        </div>

        <div class="footer">
            <p>This is an automated notification from Clip Matters.</p>
            <p>You're receiving this email because you enabled email notifications in your settings.</p>
            <p>&copy; {{ date('Y') }} Clip Matters. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
