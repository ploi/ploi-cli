<?php

namespace App\Commands;

use App\Commands\Concerns\InteractWithServer;
use App\Commands\Concerns\InteractWithSite;
use App\Traits\EnsureHasToken;
use App\Traits\HasPloiConfiguration;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class DeployCommand extends Command
{
    use EnsureHasToken, HasPloiConfiguration, InteractWithServer, InteractWithSite;

    protected $signature = 'deploy {--server=} {--site=} {--scheduled}';

    protected $description = 'Deploy your site to Ploi.io.';

    protected array $site = [];

    public function handle(): void
    {
        $this->ensureHasToken();

        [$serverId, $siteId] = $this->getServerAndSite();
        $this->site = $this->ploi->getSiteDetails($serverId, $siteId)['data'];

        $data = [];

        if ($this->ploi->getSiteDetails($serverId, $siteId)['data']['has_staging']) {
            $this->warn("{$this->site['domain']} has a staging environment.");
            $deployToProduction = confirm(
                label: 'Do you want to deploy to production? (yes/no)',
                default: false
            );

            if ($deployToProduction) {
                $this->deploy($serverId, $siteId, $this->site['domain'], [], true);

                return;
            }
        }

        if ($this->option('scheduled')) {
            $scheduledDatetime = text(
                label: 'Please enter the date and time for the deployment:',
                required: true,
                validate: fn (string $value) => match (true) {
                    ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}$/', $value) => 'Please enter a valid date and time.',
                    strtotime($value) < time() => 'Please enter a date and time in the future.',
                    default => null,
                },
                hint: 'A date in the following format: 2023-01-01 10:00 in your own timezone.'
            );
            $this->success("Scheduled deployment for {$this->site['domain']} at {$scheduledDatetime}.");

            $data['scheduled'] = $scheduledDatetime;
        }

        $this->deploy($serverId, $siteId, $this->site['domain'], $data);
    }

    protected function deploy($serverId, $siteId, $domain, $data, $deployToProduction = false): void
    {
        $deploying = $deployToProduction
            ? $this->ploi->deployToProduction($serverId, $siteId)
            : $this->ploi->deploySite($serverId, $siteId, $data);

        if (! isset($deploying['message'])) {
            $this->error($deploying['error']);
            exit();
        }

        $this->info($deploying['message']);

        $statusChecked = spin(
            callback: function () use ($serverId, $siteId, $domain) {
                while (true) {
                    sleep(10);

                    $deploymentStatus = $this->ploi->getSiteDetails($serverId, $siteId)['data']['status'] ?? 'deploying';

                    $statusMap = [
                        'active' => ['type' => 'success', 'message' => "Deployment completed successfully. Site is live on: {$domain}"],
                        'deploy-failed' => ['type' => 'error', 'message' => 'Your recent deployment has failed, please check recent deploy log for errors.'],
                    ];

                    return $statusMap[$deploymentStatus] ?? ['type' => 'warn', 'message' => 'Deployment status is unknown. Please check manually.'];
                }
            },
            message: 'Checking deployment status...'
        );

        $this->console($statusChecked['message'], $statusChecked['type']);
    }
}
