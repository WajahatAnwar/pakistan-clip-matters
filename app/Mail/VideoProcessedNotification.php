<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Video;
use App\Models\User;

class VideoProcessedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $video;
    public $user;
    public $processingTime;
    public $status;

    /**
     * Create a new message instance.
     */
    public function __construct(Video $video, User $user, $processingTime = null, $status = 'completed')
    {
        $this->video = $video;
        $this->user = $user;
        $this->processingTime = $processingTime;
        $this->status = $status;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->status === 'completed' 
            ? 'Video Processing Completed' 
            : 'Video Processing Failed';

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.video-processed',
            with: [
                'videoTitle' => $this->video->title,
                'videoPath' => $this->video->dropbox_video_path,
                'userName' => $this->user->name,
                'status' => $this->status,
                'processingTime' => $this->processingTime,
                'videoUrl' => route('videos.show', $this->video->id),
                'transcriptLength' => strlen($this->video->transcript ?? ''),
                'duration' => $this->video->duration,
                'language' => $this->video->language_detected ?? 'N/A',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
