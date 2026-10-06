<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Une prédication publiée sur le site : un lien vidéo ou un audio léger. */
class Sermon extends Model
{
    use Auditable, BelongsToOrganization;

    /** Un audio léger : une heure de parole en MP3 à 32 kbit/s tient en 15 Mo. */
    public const AUDIO_MAX_KB = 15360;

    protected $guarded = ['id'];

    protected $attributes = ['is_published' => true];

    protected function casts(): array
    {
        return ['preached_on' => 'date', 'is_published' => 'boolean', 'audio_size' => 'integer'];
    }

    /** L'adresse à intégrer dans la page, pour une vidéo YouTube ou Facebook ; null sinon. */
    public function embedUrl(bool $autoplay = false): ?string
    {
        $url = (string) $this->video_url;
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1].($autoplay ? '?autoplay=1' : '');
        }
        if (preg_match('~^https://(?:www\.|m\.|web\.)?(?:facebook\.com|fb\.watch)/~', $url)) {
            return 'https://www.facebook.com/plugins/video.php?href='.rawurlencode($url).'&show_text=false'.($autoplay ? '&autoplay=true' : '');
        }

        return null;
    }

    public function platform(): ?string
    {
        return match (true) {
            str_contains((string) $this->video_url, 'youtu') => 'YouTube',
            str_contains((string) $this->video_url, 'fb.watch') || str_contains((string) $this->video_url, 'facebook.com') => 'Facebook',
            default => $this->video_url ? __('Vidéo') : null,
        };
    }
}
