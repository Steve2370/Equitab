<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;
use App\Models\Subscription;

class GenerateSitemap extends Command
{
    protected $signature = 'equitab:generate-sitemap';
    protected $description = 'Générer le sitemap.xml';

    public function handle(): void
    {
        $sitemap = Sitemap::create();

        $sitemap->add(Url::create('/')->setPriority(1.0)->setChangeFrequency('daily'));
        $sitemap->add(Url::create('/services')->setPriority(0.9)->setChangeFrequency('daily'));
        $sitemap->add(Url::create('/login')->setPriority(0.7));
        $sitemap->add(Url::create('/register')->setPriority(0.8));
        $sitemap->add(Url::create('/conditions')->setPriority(0.3));
        $sitemap->add(Url::create('/confidentialite')->setPriority(0.3));
        $sitemap->add(Url::create('/charte')->setPriority(0.3));

        Subscription::where('is_active', true)->each(function ($sub) use ($sitemap) {
            $sitemap->add(
                Url::create("/groups/service/{$sub->slug}")
                    ->setPriority(0.8)
                    ->setChangeFrequency('hourly')
            );
        });

        $sitemap->writeToFile(public_path('sitemap.xml'));
        $this->info('Sitemap généré !');
    }
}
