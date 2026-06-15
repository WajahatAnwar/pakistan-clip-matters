# Video Management System

## Overview
The Video Management system handles the complete lifecycle of video processing, from upload to speaker tagging.

## Processing Pipeline

The system follows a 4-step processing pipeline:

```
1. Dropbox Upload → 2. YouTube Upload → 3. Transcription → 4. Speaker Tagging
```

### Step Details

#### 1. Dropbox Upload (`onDropbox`)
- Uploads video file to Dropbox cloud storage
- Provides backup and accessibility
- Status: `video.onDropbox` (boolean)

#### 2. YouTube Upload (`onYoutube`)
- Uploads video to YouTube
- Generates video URL and thumbnail
- Status: `video.onYoutube` (boolean)
- Data: `video.youtube_url`, `video.youtube_video_id`

#### 3. Transcription (`isTranscript`)
- Generates video transcript using AssemblyAI
- Detects language automatically
- Status: `video.isTranscript` (boolean)
- Data: `video.transcript_id`, `video.language_detected`

#### 4. Speaker Tagging (`isTagged`)
- Identifies speakers using Pyannote AI
- Maps speakers to voice samples
- Status: `video.isTagged` (boolean)
- Data: `video.pyannote_job_id`

## Video Data Structure

```javascript
{
  id: 6,
  title: "Test Video - 2025-11-18 14:33:04",
  filename: "testing image.mp4",
  description: "Automated test processing",
  
  // Processing Status
  processing_status: "completed", // pending | processing | completed | failed
  isTracked: true,
  
  // Step Completion Flags
  onDropbox: true,
  onYoutube: true,
  isTranscript: true,
  isTagged: true,
  
  // YouTube Data
  youtube_url: "https://www.youtube.com/watch?v=iZ7MK8rAJJY",
  youtube_video_id: "iZ7MK8rAJJY",
  
  // Transcription Data
  transcript_id: "087fd8ce-a893-48b6-bbdc-de0db2dfc8ef",
  language_detected: "hi", // ISO language code
  
  // Speaker Identification Data
  pyannote_job_id: "e79ee438-becf-40ff-bdfd-edf214bdc6b0",
  
  // Timestamps
  created_at: "2025-11-18T14:33:04.000000Z"
}
```

## Component Usage

### All Videos Component

```jsx
import All from '@/Pages/AdminSide/VideoManagement/All';

// In your page component
<All videos={videos} />
```

**Props:**
- `videos` (array): Array of video objects

### Features

#### 1. Video Thumbnail Display
- Automatically extracts YouTube thumbnails from video URLs
- Uses format: `https://img.youtube.com/vi/{VIDEO_ID}/hqdefault.jpg`
- Falls back to placeholder if no YouTube URL exists

#### 2. Progress Tracking
- Visual stepper shows completion of each processing step
- Green checkmarks for completed steps
- Gray icons for pending steps
- Active icons for transcription and speaker tagging when complete

#### 3. Status Indicators
- **Not Tracked**: Gray "Track" button visible
- **Processing**: Spinning icon with percentage (0-100%)
- **Completed**: Green checkmark with "Tracked" label
- **Failed**: Red "Failed" label

#### 4. Bulk Actions
- Select multiple videos using checkboxes
- Bulk track button enables when videos are selected
- Button shows count of selected videos

## Video Helper Utilities

Located at: `resources/js/Utils/videoHelpers.js`

### Available Functions

#### YouTube Functions
```javascript
import { getYoutubeId, getYoutubeThumbnail } from '@/Utils/videoHelpers';

// Extract video ID
const videoId = getYoutubeId('https://youtu.be/Sg0nsmU3hds');
// Returns: 'Sg0nsmU3hds'

// Get thumbnail URL
const thumbnail = getYoutubeThumbnail('https://youtu.be/Sg0nsmU3hds', 'hq');
// Returns: 'https://img.youtube.com/vi/Sg0nsmU3hds/hqdefault.jpg'

// Thumbnail qualities: 'default', 'mq', 'hq', 'sd', 'maxres'
```

#### Processing Status
```javascript
import { getProcessingStatus } from '@/Utils/videoHelpers';

const status = getProcessingStatus(video);
// Returns:
// {
//   currentStep: 'Speaker Tagging',
//   completedSteps: 3,
//   totalSteps: 4,
//   percentage: 75,
//   isComplete: false
// }
```

#### Formatting Functions
```javascript
import { 
  formatDuration, 
  formatFileSize, 
  formatTimestamp 
} from '@/Utils/videoHelpers';

formatDuration(125); // "02:05"
formatFileSize(1048576); // "1 MB"
formatTimestamp("2025-11-18T14:33:04.000000Z", true); 
// "Nov 18, 2025, 02:33 PM"
```

## API Integration

### Track Video
```javascript
const handleTrackVideo = async (videoId) => {
  try {
    const response = await fetch(`/api/videos/${videoId}/track`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      }
    });
    
    const data = await response.json();
    console.log('Video tracking started:', data);
  } catch (error) {
    console.error('Failed to track video:', error);
  }
};
```

### Bulk Track Videos
```javascript
const handleBulkTrack = async (videoIds) => {
  try {
    const response = await fetch('/api/videos/bulk-track', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ video_ids: videoIds })
    });
    
    const data = await response.json();
    console.log('Bulk tracking started:', data);
  } catch (error) {
    console.error('Failed to bulk track:', error);
  }
};
```

## Styling

The component uses Material-UI (MUI) with custom dark theme styling:

- **Background**: `#191919` (Dark gray)
- **Headers**: `#222222` (Lighter gray)
- **Primary Action**: `#EE1D52` (Red)
- **Success**: `#22C55E` (Green)
- **Processing**: Orange spinner
- **Text**: White and gray tones

## Real-time Updates

For real-time video processing updates, consider implementing:

1. **Polling**: Check video status every N seconds
```javascript
useEffect(() => {
  const interval = setInterval(() => {
    // Fetch latest video data
    fetchVideos();
  }, 5000); // Poll every 5 seconds
  
  return () => clearInterval(interval);
}, []);
```

2. **WebSockets**: Real-time push updates
```javascript
import Echo from 'laravel-echo';

const echo = new Echo({
  broadcaster: 'pusher',
  // ... config
});

echo.channel('videos')
  .listen('VideoProcessingUpdate', (e) => {
    console.log('Video updated:', e.video);
    // Update local state
  });
```

## Troubleshooting

### Videos Not Displaying
- Check that `videos` prop is being passed correctly
- Verify video data structure matches expected format
- Check browser console for errors

### Thumbnails Not Loading
- Verify `youtube_url` is present in video data
- Check URL format is valid YouTube URL
- Ensure YouTube video is public/accessible

### Processing Status Not Updating
- Implement polling or WebSocket updates
- Check backend job processing is running
- Verify database is being updated correctly

## Best Practices

1. **Error Handling**: Always handle API errors gracefully
2. **Loading States**: Show loading indicators during data fetch
3. **Optimistic Updates**: Update UI immediately, sync with backend
4. **Pagination**: Use DataGrid pagination for large video lists
5. **Caching**: Cache video thumbnails to reduce API calls
6. **Accessibility**: Ensure all interactive elements are keyboard accessible

## Future Enhancements

- [ ] Video preview on hover
- [ ] Download transcript/tags
- [ ] Edit video metadata
- [ ] Delete videos
- [ ] Filter by processing status
- [ ] Search by title/description
- [ ] Sort by date/status
- [ ] Drag & drop video upload
- [ ] Video analytics dashboard
