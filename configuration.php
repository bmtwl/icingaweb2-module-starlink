<?php

/** @var \Icinga\Application\Modules\Module $this */

// Leaflet JS comes from the map module (unscoped). Its CSS is scoped to
// .module-map, so ship our own copy for .module-starlink containers.
$this->provideCssFile('vendor/leaflet.css');

$this->providePermission('starlink/nickname', $this->translate('Set terminal nicknames'));

$section = $this->menuSection(N_('Starlink'))
    ->setIcon('globe')
    ->setUrl('starlink/terminals')
    ->setPriority(72);
$section->add(N_('Terminals'))->setUrl('starlink/terminals')->setPriority(10);
$section->add(N_('Activity'))->setUrl('starlink/activity')->setPriority(20);
$section->add(N_('Alerts'))->setUrl('starlink/alerts')->setPriority(30);
$section->add(N_('Collector'))->setUrl('starlink/health')->setPriority(40);

$this->provideConfigTab('backend', [
    'title' => $this->translate('Configure the Starlink backend'),
    'label' => $this->translate('Backend'),
    'url'   => 'config/backend'
]);
