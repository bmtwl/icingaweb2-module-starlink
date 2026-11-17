<?php

namespace Icinga\Module\Starlink\Web;

use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use ipl\Html\Html;
use ipl\Html\Table;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use ipl\Web\Widget\Link;
use Icinga\Module\Starlink\Location;
use Icinga\Module\Starlink\Settings;

abstract class Controller extends CompatController
{
    /**
     * Returns false (and renders a hint) when the backend isn't usable yet
     */
    protected function backendReady(): bool
    {
        try {
            if (Db::hasSchema()) {
                return true;
            }
            $message = $this->translate('The database has no Starlink schema yet. Start the collector daemon'
                . ' or run "icingacli starlink schema apply".');
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }
        $this->addContent(Html::tag('p', ['class' => 'starlink-hint'], $message));

        return false;
    }

    protected function terminalName(array $t): string
    {
        return ($t['nickname'] ?? '') !== '' ? $t['nickname'] : $t['device_id'];
    }

    protected function terminalLink(string $id, ?string $nickname = null): Link
    {
        $link = new Link(
            ($nickname ?? '') !== '' ? $nickname : $id,
            Url::fromPath('starlink/terminal', ['id' => $id])
        );
        if (($nickname ?? '') !== '') {
            $link->addAttributes(['title' => $id]);
        }

        return $link;
    }

    protected function mapFeature(array $t, array $loc, bool $link): array
    {
        return [
            'label'    => $this->terminalName($t),
            'status'   => $this->statusKey($t),
            'lat'      => $loc['lat'],
            'lng'      => $loc['lng'],
            'boundary' => $loc['boundary'],
            'url'      => $link
                ? Url::fromPath('starlink/terminal', ['id' => $t['device_id']])->getAbsoluteUrl()
                : null,
        ];
    }

    protected function table(array $header): Table
    {
        $table = new Table();
        $table->addAttributes(['class' => 'common-table table-row-selectable', 'data-base-target' => '_next']);
        $table->getHeader()->addHtml(Table::row($header, null, 'th'));

        return $table;
    }

    protected function nameValue(array $pairs): Table
    {
        $table = new Table();
        $table->addAttributes(['class' => 'name-value-table']);
        foreach ($pairs as $name => $value) {
            $table->getBody()->addHtml(Html::tag('tr', [
                Html::tag('th', $name),
                Html::tag('td', $value === null || $value === '' ? '-' : $value),
            ]));
        }

        return $table;
    }

    protected function status(array $t): array
    {
        $lastSeen = $t['last_seen_e'] === null ? null : (float) $t['last_seen_e'];
        $online = $lastSeen !== null && time() - $lastSeen <= 120;
        $parts = [Html::tag(
            'span',
            ['class' => 'starlink-badge ' . ($online ? 'online' : 'offline')],
            $online ? $this->translate('online') : $this->translate('offline')
        )];
        if ($online && ! empty($t['activity_id'])) {
            $parts[] = Html::tag('span', ['class' => 'starlink-badge active'], $this->translate('carrying traffic'));
        }
        $alerts = Format::jsonList($t['active_alerts'] ?? '[]');
        if ($online && $alerts) {
            $parts[] = Html::tag('span', ['class' => 'starlink-badge alert'], implode(', ', $alerts));
        }

        return $parts;
    }

    protected function h2(string $text)
    {
        return Html::tag('h2', $text);
    }

    protected function statusKey(array $t): string
    {
        $lastSeen = $t['last_seen_e'] === null ? null : (float) $t['last_seen_e'];
        if ($lastSeen === null || time() - $lastSeen > 120) {
            return 'offline';
        }

        return empty($t['activity_id']) ? 'online' : 'active';
    }

    protected function mapWidget(array $features, string $key, string $size)
    {
        return Html::tag('div', [
            'class'                 => 'starlink-map ' . $size,
            'data-map-key'          => $key,
            'data-features'         => Db::json($features),
            'data-tile-url'         => Settings::tileUrl(),
            'data-tile-attribution' => Settings::tileAttribution(),
        ]);
    }

    protected function mapUnavailable()
    {
        return Html::tag('p', ['class' => 'starlink-hint'], sprintf(
            $this->translate('Map unavailable (is the PostgreSQL h3 extension enabled in this database?): %s'),
            Location::$error
        ));
    }
}
