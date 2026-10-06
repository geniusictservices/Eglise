<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\CashAccount;
use App\Models\Event;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\Sermon;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Démonstration des sites vitrines : un par mise en page. Himbi (chaleureux)
 * avec ses prédications et la page des dons, Katindo (lumière) avec une photo
 * du Nyiragongo au-dessus du lac, et le siège (solennel) avec ses paroisses.
 */
class DemoWebsites
{
    public function build(Organization $siege, Organization $himbi, Organization $katindo, bool $withFiles = true): void
    {
        $websites = app(Websites::class);
        $lastSunday = today()->isSunday() ? today() : today()->previous(Carbon::SUNDAY);

        app(CurrentOrganization::class)->within($himbi, function () use ($himbi, $websites, $lastSunday, $withFiles) {
            $websites->save($himbi, [
                'is_published' => true, 'theme' => 'chaleureux',
                'pages' => ['programme', 'evenements', 'annonces', 'predications', 'a-propos', 'don', 'contact'],
                'tagline' => 'Goma, Himbi II · Une famille qui prie',
                'welcome_title' => 'Bienvenue à la Paroisse de Himbi',
                'welcome_text' => 'Que vous soyez de passage à Goma ou à la recherche d’une église où grandir, vous êtes attendu. Nos cultes ont lieu en français et en swahili, avec la chorale Les Voix de Sion.',
                'about_text' => "La Paroisse de Himbi est née en 1994 d’une cellule de prière réunie chez la famille Kahindo, au bord du lac. Elle rassemble aujourd’hui près de quatre cents fidèles, des enfants de l’école du dimanche aux mamans de la prière du jeudi.\n\nNous sommes membres de la Communauté Évangélique de la Paix, présente dans le Nord et le Sud-Kivu.",
                'beliefs_text' => "Nous croyons en un seul Dieu, Père, Fils et Saint-Esprit.\n\nNous croyons que la Bible est la Parole de Dieu, et que le salut est offert à tous par la foi en Jésus-Christ.\n\nNous croyons que l’Église est appelée à servir sa ville : les veuves, les orphelins, les déplacés.",
                'pastor_name' => 'Pasteur Daniel Paluku',
                'pastor_message' => 'Une église n’est pas un bâtiment, c’est une famille. Venez comme vous êtes : ici, chacun a sa place, et personne ne marche seul.',
                'giving_text' => '« Que chacun donne comme il l’a résolu en son cœur, sans tristesse ni contrainte ; car Dieu aime celui qui donne avec joie. » 2 Corinthiens 9.7',
                'giving_accounts' => CashAccount::whereIn('kind', ['mobile', 'bank'])->pluck('id')->all(),
                'giving_categories' => FinanceCategory::where('type', 'income')->whereIn('name', ['Dîme', 'Offrande d’action de grâce', 'Promesses et projets'])->pluck('id')->all(),
                'whatsapp' => '+243990000106',
                'map_url' => 'https://maps.google.com/?q=-1.6740,29.2285',
                'facebook_url' => 'https://facebook.com/cep.himbi',
                'youtube_url' => 'https://youtube.com/@cephimbi',
            ]);

            // La réunion des responsables reste entre eux ; deux annonces sont aussi pour le public.
            Event::where('title', 'Réunion des responsables')->update(['is_public' => false]);
            Event::whereIn('title', ['Convention des jeunes 2026', 'Évangélisation au marché de Virunga'])->update(['is_public' => true]);
            Announcement::where(fn ($q) => $q->where('title', 'like', 'Convention des jeunes%')->orWhere('title', 'like', 'Collecte pour les familles%'))->update(['is_public' => true]);

            $sermon = fn (int $weeksAgo, array $data) => Sermon::create($data + ['organization_id' => $himbi->id, 'preached_on' => $lastSunday->copy()->subWeeks($weeksAgo)->toDateString()]);
            $sermon(0, ['title' => 'Marcher par la foi, non par la vue', 'preacher' => 'Pasteur Daniel Paluku', 'passage' => '2 Corinthiens 5.1-10',
                'summary' => "Quand la ville tremble et que l’avenir est incertain, Paul nous rappelle que notre espérance ne dépend pas de ce que nous voyons.\n\nTrois appels pour la semaine : prier chaque matin pour notre quartier, visiter une famille éprouvée, et rendre grâce pour ce que Dieu a déjà fait.",
                'video_url' => 'https://youtu.be/Xk7mWq3Lp0A']);
            $audio = $sermon(1, ['title' => 'Le bon berger connaît ses brebis', 'preacher' => 'Évangéliste Josué Kakule', 'passage' => 'Jean 10.1-18',
                'summary' => 'Jésus connaît chacun par son nom. Un message pour ceux qui se sentent oubliés, et pour ceux qui sont appelés à prendre soin des autres.']);
            $sermon(2, ['title' => 'Une maison bâtie sur le roc', 'preacher' => 'Pasteur Daniel Paluku', 'passage' => 'Matthieu 7.24-29',
                'summary' => 'Entendre la Parole ne suffit pas : la mettre en pratique, c’est bâtir pour les jours d’orage.', 'video_url' => 'https://www.facebook.com/cep.himbi/videos/1029384756']);
            if ($withFiles) {
                $path = 'sermons/'.$himbi->id.'-demo-berger.mp3';
                Storage::disk('local')->put($path, (string) file_get_contents(resource_path('demo/predication.mp3')));
                $audio->update(['audio_path' => $path, 'audio_size' => Storage::disk('local')->size($path)]);
            } else {
                $audio->update(['video_url' => 'https://youtu.be/Bq9tLm2Vx4c']);
            }
        });

        app(CurrentOrganization::class)->within($katindo, function () use ($katindo, $websites, $withFiles, $lastSunday) {
            $calendar = app(Calendar::class);
            $calendar->save($katindo, ['title' => 'Culte du dimanche', 'kind' => 'service', 'starts_on' => $lastSunday->copy()->subWeeks(40)->toDateString(),
                'start_time' => '09:00', 'end_time' => '12:00', 'place' => 'Temple de Katindo', 'repeats' => 'weekly', 'tracks_attendance' => true]);
            $calendar->save($katindo, ['title' => 'Prière du mercredi', 'kind' => 'prayer', 'starts_on' => $lastSunday->copy()->subWeeks(40)->addDays(3)->toDateString(),
                'start_time' => '17:00', 'end_time' => '18:30', 'place' => 'Temple de Katindo', 'repeats' => 'weekly']);
            $website = $websites->save($katindo, [
                'is_published' => true, 'theme' => 'lumiere',
                'pages' => ['programme', 'evenements', 'a-propos', 'contact'],
                'tagline' => 'Goma, Katindo',
                'welcome_title' => 'Une église ouverte sur la ville',
                'welcome_text' => 'Au pied du Nyiragongo, la Paroisse de Katindo accueille chaque dimanche familles, étudiants et déplacés.',
                'about_text' => 'Fondée en 2003, la paroisse de Katindo est membre de la Communauté Évangélique de la Paix.',
                'whatsapp' => '+243990000110',
            ]);
            if ($withFiles) {
                $website->update(['cover_path' => $this->landscape($katindo)]);
            }
        });

        app(CurrentOrganization::class)->within($siege, function () use ($siege, $websites) {
            $websites->save($siege, [
                'is_published' => true, 'theme' => 'solennel',
                'pages' => ['a-propos', 'paroisses', 'contact'],
                'tagline' => 'Nord-Kivu et Sud-Kivu',
                'welcome_title' => 'Communauté Évangélique de la Paix',
                'welcome_text' => 'Une communauté d’églises au service de l’Évangile et de la paix dans l’Est de la République démocratique du Congo.',
                'about_text' => "La Communauté Évangélique de la Paix rassemble des paroisses du Nord-Kivu et du Sud-Kivu, organisées en régions et en secteurs.\n\nSon siège est à Goma.",
                'pastor_name' => 'Rév. Émmanuel Muhindo',
                'pastor_message' => 'Bâtissons ensemble des communautés qui prient, qui servent et qui réconcilient.',
            ]);
        });
    }

    /** Une photo de démonstration : le Nyiragongo au lever du jour, au-dessus du lac Kivu. */
    private function landscape(Organization $organization): string
    {
        [$w, $h] = [1600, 760];
        $img = imagecreatetruecolor($w, $h);
        $mix = fn (array $a, array $b, float $t) => imagecolorallocate($img, ...array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $t), $a, $b));
        // Ciel : de l'indigo à l'ocre de l'aube.
        for ($y = 0; $y < 520; $y++) {
            imageline($img, 0, $y, $w, $y, $mix([38, 44, 96], [236, 160, 72], $y / 520));
        }
        // Le volcan et son panache.
        imagefilledpolygon($img, [180, 540, 640, 250, 760, 236, 880, 252, 1380, 540], imagecolorallocate($img, 52, 40, 52));
        imagefilledpolygon($img, [0, 540, 140, 470, 330, 510, 520, 460, 700, 540], imagecolorallocate($img, 70, 56, 66));
        imagefilledpolygon($img, [1100, 540, 1320, 430, 1500, 480, 1600, 450, 1600, 540], imagecolorallocate($img, 70, 56, 66));
        foreach ([[760, 215, 90], [800, 170, 120], [860, 120, 150], [930, 80, 170]] as $i => [$cx, $cy, $r]) {
            imagefilledellipse($img, $cx, $cy, $r * 2, (int) ($r * 1.2), imagecolorallocatealpha($img, 230, 210, 200, 70 + $i * 12));
        }
        imagefilledellipse($img, 760, 238, 60, 14, imagecolorallocate($img, 245, 120, 40));
        // Le lac, avec les reflets.
        for ($y = 540; $y < $h; $y++) {
            imageline($img, 0, $y, $w, $y, $mix([58, 74, 120], [24, 30, 66], ($y - 540) / ($h - 540)));
        }
        mt_srand(7);
        for ($i = 0; $i < 90; $i++) {
            $y = mt_rand(548, $h - 4);
            $x = mt_rand(400, 1100);
            imageline($img, $x, $y, $x + mt_rand(20, 90), $y, imagecolorallocatealpha($img, 250, 190, 110, mt_rand(60, 100)));
        }
        ob_start();
        imagejpeg($img, null, 82);
        $path = 'websites/'.$organization->id.'-demo-nyiragongo.jpg';
        Storage::disk('local')->put($path, (string) ob_get_clean());

        return $path;
    }
}
