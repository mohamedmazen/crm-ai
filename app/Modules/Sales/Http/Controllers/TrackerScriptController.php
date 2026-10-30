<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class TrackerScriptController extends Controller
{
    /**
     * Serve the public UTM and Source Attribution tracker script.
     */
    public function script(): Response
    {
        $js = <<<'JS'
(function() {
    'use strict';

    var COOKIE_NAME = '_wm_utm';
    var COOKIE_DAYS = 30;

    function getQueryParam(name) {
        var match = RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : null;
    }

    function setCookie(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + encodeURIComponent(value) + expires + "; path=/; SameSite=Lax";
    }

    function getCookie(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for (var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) == 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
        }
        return null;
    }

    // Capture incoming UTM and click identifiers
    var params = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
    var currentData = {};
    var foundAny = false;

    for (var i = 0; i < params.length; i++) {
        var val = getQueryParam(params[i]);
        if (val) {
            currentData[params[i]] = val;
            foundAny = true;
        }
    }

    // Store referrer and landing page
    if (document.referrer && document.referrer.indexOf(window.location.hostname) === -1) {
        currentData.referrer = document.referrer;
        foundAny = true;
    }
    currentData.landing_page = window.location.pathname + window.location.search;

    if (foundAny) {
        try {
            var jsonStr = JSON.stringify(currentData);
            setCookie(COOKIE_NAME, jsonStr, COOKIE_DAYS);
            if (window.localStorage) {
                localStorage.setItem(COOKIE_NAME, jsonStr);
            }
        } catch(e) {}
    }

    // Helper to get stored attribution
    function getStoredAttribution() {
        try {
            var raw = getCookie(COOKIE_NAME) || (window.localStorage ? localStorage.getItem(COOKIE_NAME) : null);
            return raw ? JSON.parse(raw) : {};
        } catch(e) {
            return {};
        }
    }

    // Auto-populate hidden inputs on forms
    function populateFormInputs() {
        var attr = getStoredAttribution();
        if (!attr || Object.keys(attr).length === 0) return;

        var forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            for (var key in attr) {
                if (attr.hasOwnProperty(key)) {
                    var input = form.querySelector('input[name="' + key + '"]');
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        form.appendChild(input);
                    }
                    input.value = attr[key];
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', populateFormInputs);
    } else {
        populateFormInputs();
    }

    // Expose global tracker API
    window.WhatsMineTracker = {
        getAttribution: getStoredAttribution,
        populateForms: populateFormInputs
    };
})();
JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

