/* Starlink module maps. Leaflet (global L) is provided by the map module. */
(function (Icinga, $) {
    'use strict';

    var COLORS = { online: '#44bb77', active: '#ffaa44', offline: '#999999' };
    // map key -> { center, zoom }; keeps the user's view across auto-refresh
    var views = {};

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function popupHtml(f) {
        var html = '<strong>' + esc(f.label) + '</strong><br>' + esc(f.status)
            + '<br>' + f.lat.toFixed(4) + ', ' + f.lng.toFixed(4);
        if (f.url) {
            html += '<br><a href="' + esc(f.url) + '" data-base-target="_next">Details</a>';
        }
        return html;
    }

    function initMap(el) {
        if (el.getAttribute('data-initialized') || typeof L === 'undefined') {
            return;
        }
        el.setAttribute('data-initialized', '1');

        var features;
        try {
            features = JSON.parse(el.getAttribute('data-features') || '[]');
        } catch (e) {
            features = [];
        }
        var key = el.getAttribute('data-map-key') || '';
        var map = L.map(el, { scrollWheelZoom: false, worldCopyJump: true });
        L.tileLayer(el.getAttribute('data-tile-url'), {
            maxZoom: 18,
            attribution: el.getAttribute('data-tile-attribution') || ''
        }).addTo(map);

        var bounds = L.latLngBounds([]);
        features.forEach(function (f) {
            var color = COLORS[f.status] || COLORS.offline;
            var html = popupHtml(f);
            var cell = L.polygon(f.boundary, { color: color, weight: 2, fillOpacity: 0.15 }).addTo(map);
            var dot = L.circleMarker([f.lat, f.lng], {
                radius: 5, color: color, fillColor: color, fillOpacity: 0.9, weight: 1
            }).addTo(map);
            cell.bindPopup(html);
            dot.bindPopup(html);
            dot.bindTooltip(esc(f.label));
            bounds.extend(cell.getBounds());
        });

        if (views[key]) {
            map.setView(views[key].center, views[key].zoom);
        } else if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [20, 20], maxZoom: 10 });
        } else {
            map.setView([0, 0], 2);
        }
        map.on('moveend', function () {
            views[key] = { center: map.getCenter(), zoom: map.getZoom() };
        });
        // container may not have its final size yet when rendered
        setTimeout(function () { map.invalidateSize(); }, 0);
    }

    var Starlink = function (module) {
        this.module = module;
        module.on('rendered', this.onRendered);
    };

    Starlink.prototype.onRendered = function (event) {
        $(event.target).find('.starlink-map').addBack('.starlink-map').each(function () {
            initMap(this);
        });
    };

    Icinga.availableModules.starlink = Starlink;
}(Icinga, jQuery));
