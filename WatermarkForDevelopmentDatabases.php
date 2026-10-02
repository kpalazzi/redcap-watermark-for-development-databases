<?php

namespace HMRI\WatermarkForDevelopmentDatabases;

use ExternalModules\AbstractExternalModule;

class WatermarkForDevelopmentDatabases extends AbstractExternalModule {

    // Built-in defaults (used when the Control Center value is missing or unusable)
    const DEFAULT_TEXT      = 'TEST DATA ONLY';
    const DEFAULT_COLOR     = '#F26600';
    const DEFAULT_OPACITY   = 0.1;
    const MIN_OPACITY       = 0.1;
    const DEFAULT_FONT_PT   = 90;   // 90pt = the original 120px
    const MIN_FONT_PT       = 10;
    const MAX_FONT_PT       = 300;

    // Project limits: stop project-level overrides that make the watermark hard to see.
    // Admins can change the first three in the Control Center.
    const DEFAULT_PROJECT_MIN_CHARS   = 4;    // letters/digits required in project text
    const DEFAULT_PROJECT_MIN_FONT_PT = 48;
    const DEFAULT_PROJECT_MIN_OPACITY = 0.1;
    const MAX_PROJECT_MIN_CHARS       = 50;
    const MIN_CONTRAST_VS_WHITE       = 1.5;  // colours below this contrast on white are "too light"

    // Record-count opacity ramp (system level)
    const DEFAULT_RAMP_START = 20;  // records before opacity starts to rise
    const DEFAULT_RAMP_MAX   = 30;  // records at which opacity reaches its ceiling
    const RAMP_MULTIPLIER    = 5;   // ceiling = project opacity x 5, capped at 1.0

    // System setting holding the "new projects only" cut-off timestamp (hidden in config.json)
    const CUTOFF_SETTING = 'new-project-cutoff';

    // Control Center settings that projects can override ("allow-project-overrides" in config.json)
    const APPEARANCE_KEYS = [
        'watermark-text',
        'watermark-color-picker',
        'watermark-color',
        'watermark-opacity',
        'watermark-font-size',
    ];

    // CSS class on the module's own "(this setting can be overridden on each project)" note
    const OVERRIDE_NOTE_CLASS = 'wm-override-note';

    // Watermark placement (see displayWatermark())
    const CONTENT_WIDTH_PX     = 800;   // content width the watermark is centred over, beside the left menu (about a REDCap form)
    const FIT_TO_SCREEN_FACTOR = 182;   // font size limit in vmin = factor / number of characters
    const MAX_CENTRE_Y_PX      = 450;   // centre is halfway down the window, but no lower than this (see displayWatermark())

    // For data entry forms
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        if ($this->shouldDisplayWatermark($project_id)) {
            $this->displayWatermark($project_id);
        }
    }

    /**
     * Acts on exactly four kinds of page and returns immediately on every other page.
     * "enable-every-page-hooks-on-system-pages" is set in config.json only so that the
     * Control Center External Modules page (a system page) is included.
     *
     * 1. Control Center > External Modules: fill in missing Control Center defaults, and add
     *    the Configure dialog helpers (live override labels, colour picker/code sync).
     * 2. A project's External Modules page: add the project Configure dialog helpers
     *    (live checks, colour sync, pre-ticked opt-in).
     * 3. Record status dashboard, add/edit records, survey distribution: show the watermark.
     * (Data entry forms and surveys use their own hooks below.)
     */
    function redcap_every_page_top($project_id) {
        $page = PAGE;

        // 1. System pages: only the Control Center External Modules page
        if (empty($project_id)) {
            if (!$this->isControlCenterModulePage($page, $project_id)) {
                return;
            }
            $this->seedDefaultsIfControlCenterModulePage($page, $project_id);
            $this->outputOverrideNoteToggleScript();
            $this->outputColourSyncScript();
            return;
        }

        // 2. Project External Modules page
        if ($this->isProjectModulePage($page, $project_id)) {
            if ($this->projectOverridesAllowed()) {
                $this->outputLiveValidationScript();
                $this->outputColourSyncScript();
            }
            if ($this->shouldPreTickOptIn($project_id)) {
                $this->outputOptInPreTickScript();
            }
            return;
        }

        // 3. Watermarked project pages
        if (!in_array($page, [
            'DataEntry/record_status_dashboard.php',
            'DataEntry/record_home.php',
            'Surveys/invite_participants.php',
        ], true)) {
            return;
        }

        if ($this->shouldDisplayWatermark($project_id)) {
            $this->displayWatermark($project_id);
        }
    }

    // For surveys
    function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        if ($this->shouldDisplayWatermark($project_id)) {
            $this->displayWatermark($project_id, true);
        }
    }

    // Seed Control Center defaults on first install and on upgrade from v1.0.0, so the
    // Control Center and project dialogs show real values rather than blanks.
    // config.json "default" values aren't applied reliably by the framework.
    function redcap_module_system_enable($version) {
        $this->seedSystemDefaults();
    }

    function redcap_module_system_change_version($version, $old_version) {
        $this->seedSystemDefaults();
    }

    /**
     * The Configure dialog doesn't use config.json "default" values, so Control Center
     * fields would open blank on any install where the enable/upgrade hooks didn't seed
     * them. Fill them in when an admin opens Control Center > External Modules - this
     * page loads before the dialog fetches its values. Only the Control Center page is
     * used, as it's admin-only and system settings can't be written from a project context.
     */
    protected function seedDefaultsIfControlCenterModulePage(string $page, $project_id): void {
        if (!$this->isControlCenterModulePage($page, $project_id)) {
            return;
        }

        try {
            $this->seedSystemDefaults();
            $this->resetScopeIfNotEnabledOnAll();
        } catch (\Throwable $e) {
            // Never break the Control Center page; missing values still fall back to
            // built-in defaults when the watermark is displayed.
        }
    }

    /**
     * Control Center > External Modules. REDCap sets PAGE to "manager/control_center.php" there.
     */
    protected function isControlCenterModulePage(string $page, $project_id): bool {
        return empty($project_id)
            && substr($page, -strlen('manager/control_center.php')) === 'manager/control_center.php';
    }

    /**
     * A project's External Modules page. REDCap sets PAGE to "manager/project.php" there.
     */
    protected function isProjectModulePage(string $page, $project_id): bool {
        return !empty($project_id)
            && substr($page, -strlen('manager/project.php')) === 'manager/project.php';
    }

    /**
     * The limits and Control Center values the project dialog's live checks need. The rules
     * mirror projectTextProblem() etc.; the server-side checks on save remain the authority.
     */
    protected function getLiveValidationConfig(): array {
        $site = [];
        foreach (self::APPEARANCE_KEYS as $key) {
            $site[$key] = (string) $this->getSystemSetting($key);
        }

        return [
            'prefix'      => $this->PREFIX,
            'site'        => $site,
            'minChars'    => $this->getProjectMinChars(),
            'minFont'     => $this->getProjectMinFontSize(),
            'maxFont'     => self::MAX_FONT_PT,
            'minOpacity'  => $this->getProjectMinOpacity(),
            'minContrast' => self::MIN_CONTRAST_VS_WHITE,
        ];
    }

    /**
     * Flag project-dialog problems as the user types or picks a colour, instead of only after
     * Save. Shows a red message under the field and a red border; both clear once the value is
     * fixed. Values left the same as the Control Center are never flagged (as on save).
     *
     * Uses jQuery (always present in REDCap) because the colour picker reports its changes as
     * jQuery events. The dialog is built after the page loads, so handlers are delegated and a
     * MutationObserver checks values already saved when the dialog opens.
     */
    protected function outputLiveValidationScript(): void {
        $config = json_encode($this->getLiveValidationConfig(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo <<<JS
        <script>
        (function (cfg) {
            if (!window.jQuery) return;
            var $ = window.jQuery;
            var MODAL = '#external-modules-configure-modal';

            function isOurDialog() {
                return $(MODAL).data('module') === cfg.prefix;
            }

            // Same rules as the server-side checks
            function visibleChars(text) {
                text = text.replace(/[ᅟᅠㅤﾠ]/g, '');
                var m = text.match(/[\p{L}\p{N}]/gu);
                return m ? m.length : 0;
            }
            function parseColor(value) {
                var v = value.trim().toLowerCase(), m;
                if (v === 'white') return [255, 255, 255];
                if ((m = v.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/))) {
                    var h = m[1].length === 3 ? m[1].replace(/(.)/g, '$1$1') : m[1];
                    return [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16); });
                }
                if ((m = v.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*[\d.]+\s*)?\)$/))) {
                    var rgb = [+m[1], +m[2], +m[3]];
                    return rgb.every(function (c) { return c <= 255; }) ? rgb : null;
                }
                return null;
            }
            function contrastWithWhite(rgb) {
                var lin = rgb.map(function (c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); });
                return 1.05 / (0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2] + 0.05);
            }
            function colorProblem(v) {
                var rgb = parseColor(v);
                if (!rgb) return "This isn't a valid hex code or rgb() colour.";
                if (contrastWithWhite(rgb) < cfg.minContrast) return 'This colour is too light to see. Choose a darker colour.';
                return null;
            }

            var checks = {
                'watermark-text': function (v) {
                    return visibleChars(v) < cfg.minChars ? 'Needs at least ' + cfg.minChars + ' letters or digits.' : null;
                },
                'watermark-color': colorProblem,
                'watermark-color-picker': colorProblem,
                'watermark-opacity': function (v) {
                    return (isNaN(parseFloat(v)) || !isFinite(v) || parseFloat(v) < cfg.minOpacity)
                        ? "Can't be lower than " + cfg.minOpacity + ' on this site.' : null;
                },
                'watermark-font-size': function (v) {
                    return (isNaN(parseFloat(v)) || !isFinite(v) || parseFloat(v) < cfg.minFont)
                        ? "Can't be smaller than " + cfg.minFont + 'pt on this site.' : null;
                }
            };

            function check(input) {
                var name = input.name;
                if (!checks[name] || !isOurDialog()) return;

                var value = input.value || '';
                // Blank or unchanged from the Control Center: always accepted
                var problem = (value === '' || value === cfg.site[name]) ? null : checks[name](value);

                var cell = $(input).closest('td');
                // The colour picker hides the real input and shows its own swatch
                var shown = name === 'watermark-color-picker' ? cell.find('.sp-replacer') : $(input);
                var existing = cell.find('.wm-live-error');

                shown.css(problem
                    ? { 'border-color': '#c62828', 'background-color': '#fff5f5' }
                    : { 'border-color': '', 'background-color': '' });

                // Only touch the DOM when the message changes, so the observer below settles
                if (!problem) {
                    existing.remove();
                } else if (existing.text() !== problem) {
                    existing.remove();
                    $('<div class="wm-live-error" style="color:#c62828;font-size:12px;margin-top:4px"></div>')
                        .text(problem).appendTo(cell);
                }
            }

            var selector = Object.keys(checks).map(function (n) { return MODAL + ' input[name="' + n + '"]'; }).join(',');
            $(document).on('input change', selector, function () { check(this); });

            // Check values already saved when the dialog opens
            $(function () {
                var modal = document.querySelector(MODAL);
                if (!modal || !window.MutationObserver) return;
                var pending;
                new MutationObserver(function () {
                    clearTimeout(pending);
                    pending = setTimeout(function () { $(selector).each(function () { check(this); }); }, 50);
                }).observe(modal, { childList: true, subtree: true });
            });
        })({$config});
        </script>
        JS;
    }

    /**
     * Pre-tick "Show the watermark on this project" when the project has never saved a choice,
     * so clicking Save opts in unless the user unticks it first. Nothing changes until they
     * save: older projects nobody opens stay off, so "New projects only" still means that.
     * Once a choice is saved (ticked or unticked) the dialog shows it as saved.
     */
    protected function shouldPreTickOptIn($project_id): bool {
        return $this->shouldOfferOptIn($project_id) && $this->getProjectSetting('opt-in') === null;
    }

    /**
     * REDCap's dialog shows checkboxes from saved values only, so tick it in the browser once
     * the dialog has been built. Each opening builds a new checkbox; it's marked once ticked
     * so a user who unticks it isn't overridden.
     */
    protected function outputOptInPreTickScript(): void {
        $prefix = json_encode($this->PREFIX, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo <<<JS
        <script>
        (function (prefix) {
            if (!window.jQuery || !window.MutationObserver) return;
            var $ = window.jQuery;
            var MODAL = '#external-modules-configure-modal';

            $(function () {
                var modal = document.querySelector(MODAL);
                if (!modal) return;
                new MutationObserver(function () {
                    if ($(MODAL).data('module') !== prefix) return;
                    var box = modal.querySelector('input[name="opt-in"]');
                    if (!box || box.dataset.wmPreTicked) return;
                    box.dataset.wmPreTicked = '1';
                    box.checked = true;
                    $(box).trigger('change');
                }).observe(modal, { childList: true, subtree: true });
            });
        })({$prefix});
        </script>
        JS;
    }

    /**
     * Keep the colour picker and the colour code box in step, both ways:
     * - Typing a valid code sets the picker to match straight away. Half-typed or invalid
     *   codes leave the picker alone.
     * - Choosing a colour in the picker (its "Save" button) writes the hex code into the box.
     *   Clearing the picker clears the box.
     * Both values are changed, not just the swatch, so what's shown is what's saved.
     *
     * REDCap's picker is the jQuery Spectrum plugin; "set" doesn't fire a change event, so one
     * is triggered to let the live checks re-check the field. A "syncing" flag stops each
     * update bouncing back - otherwise typing "#C00" would set the picker, and the picker
     * would rewrite the box as "#CC0000" mid-typing.
     */
    protected function outputColourSyncScript(): void {
        $prefix = json_encode($this->PREFIX, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        echo <<<JS
        <script>
        (function (prefix) {
            if (!window.jQuery) return;
            var $ = window.jQuery;
            var MODAL = '#external-modules-configure-modal';

            function isColour(value) {
                var v = value.trim().toLowerCase(), m;
                if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/.test(v)) return true;
                if ((m = v.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*[\d.]+\s*)?\)$/))) {
                    return +m[1] <= 255 && +m[2] <= 255 && +m[3] <= 255;
                }
                return false;
            }

            var syncing = false;
            function ours() { return $(MODAL).data('module') === prefix; }
            function picker() {
                var p = $(MODAL + ' input[name="watermark-color-picker"]');
                return (p.length && typeof p.spectrum === 'function') ? p : null;
            }

            // Code box -> picker
            $(document).on('input change', MODAL + ' input[name="watermark-color"]', function () {
                if (syncing || !ours() || !isColour(this.value)) return;
                var p = picker();
                if (!p) return;

                syncing = true;
                p.spectrum('set', this.value.trim());
                p.trigger('change');
                syncing = false;
            });

            // Picker -> code box
            $(document).on('change', MODAL + ' input[name="watermark-color-picker"]', function () {
                if (syncing || !ours()) return;
                var p = picker();
                if (!p) return;

                var colour = p.spectrum('get');
                var hex = colour ? colour.toHexString().toUpperCase() : '';
                var box = $(MODAL + ' input[name="watermark-color"]');
                if (!box.length || box.val().trim().toUpperCase() === hex) return;

                syncing = true;
                box.val(hex).trigger('change');
                syncing = false;
            });
        })({$prefix});
        </script>
        JS;
    }

    /**
     * Show/hide the module's "(this setting can be overridden on each project)" notes live as
     * the "Allow project-level settings to override" checkbox is ticked or unticked, before
     * saving. The notes are added by redcap_module_configuration_settings(); only this
     * module's dialog contains the checkbox or the notes, so other modules are unaffected.
     */
    protected function outputOverrideNoteToggleScript(): void {
        $note = self::OVERRIDE_NOTE_CLASS;

        echo <<<JS
        <script>
        (function () {
            // Delegated, because the Configure dialog is built after the page loads
            document.addEventListener('change', function (e) {
                var box = e.target;
                if (!box || box.name !== 'allow-project-override' || box.type !== 'checkbox') return;
                var modal = box.closest('#external-modules-configure-modal') || document;
                modal.querySelectorAll('.{$note}').forEach(function (el) {
                    el.style.display = box.checked ? 'inline' : 'none';
                });
            });
        })();
        </script>
        JS;
    }

    /**
     * Fill in any Control Center setting that has never been saved. Existing values are
     * left untouched, so admin choices survive upgrades.
     */
    protected function seedSystemDefaults() {
        $defaults = [
            'apply-to'                => 'all',
            'allow-project-override'  => true,
            'watermark-text'          => self::DEFAULT_TEXT,
            'watermark-color-picker'  => self::DEFAULT_COLOR,
            'watermark-opacity'       => (string) self::DEFAULT_OPACITY,
            'watermark-font-size'     => (string) self::DEFAULT_FONT_PT,
            'project-min-chars'       => (string) self::DEFAULT_PROJECT_MIN_CHARS,
            'project-min-font-size'   => (string) self::DEFAULT_PROJECT_MIN_FONT_PT,
            'project-min-opacity'     => (string) self::DEFAULT_PROJECT_MIN_OPACITY,
            'ramp-start'              => (string) self::DEFAULT_RAMP_START,
            'ramp-max'                => (string) self::DEFAULT_RAMP_MAX,
        ];

        foreach ($defaults as $key => $value) {
            if ($this->getSystemSetting($key) === null) {
                $this->setSystemSetting($key, $value);
            }
        }
    }

    /**
     * Maintain the "new projects only" cut-off when the system configuration is saved.
     * The framework passes an empty project ID for Control Center (system) saves.
     *
     * - Switched to "new": stamp the cut-off now (only if not already set, so re-saving
     *   other system settings doesn't move it).
     * - Switched to "all": clear the cut-off, so switching back to "new" later starts afresh.
     */
    function redcap_module_save_configuration($project_id) {
        if (!empty($project_id)) {
            $this->removeInheritedProjectColours();
            return;
        }

        // "New projects only" only applies while the module is enabled on all projects
        if ($this->isEnabledOnAllProjects() && $this->getSystemSetting('apply-to') === 'new') {
            if (empty($this->getSystemSetting(self::CUTOFF_SETTING))) {
                $now = defined('NOW') ? NOW : date('Y-m-d H:i:s');
                $this->setSystemSetting(self::CUTOFF_SETTING, $now);
            }
        } else {
            $this->removeSystemSetting(self::CUTOFF_SETTING);
        }

        $this->resetScopeIfNotEnabledOnAll();
    }

    /**
     * After a project saves its dialog, remove colour values that are just the site's colour
     * written differently (the picker saves lowercase hex; the code box is filled in from the
     * picker). REDCap only removes exact matches, so without this the project would keep a
     * copy of today's site colour and stop following the site when an admin changes it.
     */
    protected function removeInheritedProjectColours(): void {
        if (!$this->projectOverridesAllowed()) {
            return;
        }
        foreach (['watermark-color', 'watermark-color-picker'] as $key) {
            $value = $this->getProjectSetting($key);
            if ($value !== null && $value !== '' && $this->getProjectOverride($key) === null) {
                $this->removeProjectSetting($key);
            }
        }
    }

    /**
     * "Apply watermark to" is hidden and ignored while "Enable module on all projects by
     * default" is unticked. Reset it (and any cut-off) so ticking the box later opens on
     * "All projects" rather than a stale "New projects only". Runs on save and when the
     * Control Center External Modules page loads, so it also fixes values saved before
     * this rule existed.
     */
    protected function resetScopeIfNotEnabledOnAll(): void {
        if ($this->isEnabledOnAllProjects()) {
            return;
        }
        if ($this->getSystemSetting('apply-to') !== 'all') {
            $this->setSystemSetting('apply-to', 'all');
        }
        if ($this->getSystemSetting(self::CUTOFF_SETTING) !== null) {
            $this->removeSystemSetting(self::CUTOFF_SETTING);
        }
    }

    /**
     * Adjust a Configure dialog before it's displayed. This hook only changes what the
     * dialog shows; saving and getProjectSetting() use the unmodified config.json.
     */
    function redcap_module_configuration_settings($project_id, $settings) {
        if (!is_array($settings)) {
            return $settings;
        }

        return empty($project_id)
            ? $this->adjustSystemDialog($settings)
            : $this->adjustProjectDialog($settings, $project_id);
    }

    /**
     * Project dialog:
     * - "Show the watermark on this project" only appears where it applies (an older project
     *   under "New projects only"), placed just after REDCap's own options.
     * - When project overrides are off, remove the appearance fields (their values would be
     *   ignored) and say where the appearance is set. REDCap's own project options, such as
     *   "Hide this module from non-admins", are left in place.
     */
    protected function adjustProjectDialog(array $settings, $project_id): array {
        $settings = $this->placeOptInSetting($settings, $project_id);

        if ($this->projectOverridesAllowed()) {
            return $this->showProjectLimitsInLabels($settings);
        }

        $settings = array_values(array_filter($settings, function ($setting) {
            return !in_array($setting['key'] ?? null, self::APPEARANCE_KEYS, true);
        }));

        $settings[] = [
            'key'  => 'appearance-locked-note',
            'name' => 'The watermark text, colour, opacity and size are set by your REDCap administrator for all projects.',
            'type' => 'descriptive',
        ];

        return $settings;
    }

    /**
     * Remove the opt-in checkbox where it doesn't apply; otherwise move it from the end of
     * the list (REDCap lists overridable settings first) to just after REDCap's own options.
     */
    protected function placeOptInSetting(array $settings, $project_id): array {
        $keys  = array_column($settings, 'key');
        $index = array_search('opt-in', $keys, true);
        if ($index === false) {
            return $settings;
        }

        $row = $settings[$index];
        unset($settings[$index]);
        $settings = array_values($settings);

        if (!$this->shouldOfferOptIn($project_id)) {
            return $settings;
        }

        $position = 0;
        foreach (array_column($settings, 'key') as $i => $key) {
            if (strpos((string) $key, 'reserved-') === 0) {
                $position = $i + 1;
            }
        }
        array_splice($settings, $position, 0, [$row]);

        return $settings;
    }

    /**
     * Project dialog labels show this site's limits rather than the absolute ranges, e.g.
     * "(pt, 48 – 300)" instead of "(pt, 10 – 300)".
     */
    protected function showProjectLimitsInLabels(array $settings): array {
        $labels = [
            'watermark-text'      => '<b>Watermark text</b> (at least ' . $this->getProjectMinChars() . ' letters or digits)',
            'watermark-opacity'   => '<b>Watermark opacity</b> (' . $this->formatNumber($this->getProjectMinOpacity()) . ' – 1.0)',
            'watermark-font-size' => '<b>Watermark text size</b> (pt, ' . $this->formatNumber($this->getProjectMinFontSize()) . ' – ' . self::MAX_FONT_PT . ')',
        ];

        foreach ($settings as $i => $setting) {
            $key = $setting['key'] ?? null;
            if (isset($labels[$key])) {
                $settings[$i]['name'] = $labels[$key];
            }
        }
        return $settings;
    }

    /** 48.0 -> "48", 0.15 -> "0.15" */
    protected function formatNumber(float $number): string {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * Control Center dialog:
     *
     * - Replace REDCap's own "(this setting can be overridden on each project)" label text
     *   with the module's copy, so it can be shown and hidden live with the
     *   "Allow project-level settings to override" checkbox (see
     *   outputOverrideNoteToggleScript()). REDCap adds its text whenever the
     *   "allow-project-overrides" flag is present, so the flag is removed from the dialog
     *   copy of the settings only.
     * - Move "Apply watermark to" directly under REDCap's built-in "Enable module on all
     *   projects by default" checkbox. The framework always lists its own settings first,
     *   so this can't be done in config.json. If the built-in setting isn't found, the
     *   order is left alone.
     */
    protected function adjustSystemDialog(array $settings): array {
        $display = $this->projectOverridesAllowed() ? 'inline' : 'none';
        $note    = htmlspecialchars($this->getOverridableNoteText(), ENT_QUOTES);

        foreach ($settings as $i => $setting) {
            if (in_array($setting['key'] ?? null, self::APPEARANCE_KEYS, true) && !empty($setting['allow-project-overrides'])) {
                unset($settings[$i]['allow-project-overrides']);
                $settings[$i]['name'] .= '<span class="' . self::OVERRIDE_NOTE_CLASS . '" style="display:' . $display . '"><br>' . $note . '</span>';
            }
        }

        $keys      = array_column($settings, 'key');
        $enabled   = array_search('enabled', $keys, true);
        $apply_to  = array_search('apply-to', $keys, true);

        if ($enabled === false || $apply_to === false) {
            return $settings;
        }

        $row = $settings[$apply_to];
        unset($settings[$apply_to]);
        $settings = array_values($settings);

        // Re-find "enabled" after removal, then insert straight after it
        $enabled = array_search('enabled', array_column($settings, 'key'), true);
        array_splice($settings, $enabled + 1, 0, [$row]);

        return $settings;
    }

    /**
     * REDCap's own wording (translated) for "(this setting can be overridden on each project)",
     * falling back to English outside REDCap.
     */
    protected function getOverridableNoteText(): string {
        if (class_exists('ExternalModules\ExternalModules', false)) {
            try {
                $text = \ExternalModules\ExternalModules::tt('em_manage_121');
                if (is_string($text) && $text !== '') {
                    return $text;
                }
            } catch (\Throwable $e) {
                // fall through to English
            }
        }
        return '(this setting can be overridden on each project)';
    }

    /**
     * REDCap's built-in "Enable module on all projects by default" (system setting "enabled").
     */
    protected function isEnabledOnAllProjects(): bool {
        return filter_var($this->getSystemSetting('enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * When project overrides are switched off in the Control Center, hide the project-level
     * Configure button from non-admins - anything they saved would be ignored anyway.
     * Admins still see it so they can inspect project values.
     */
    function redcap_module_configure_button_display($project_id = null) {
        if (empty($project_id)) {
            return true;
        }
        // Older projects under "New projects only" need the button to opt in, even when
        // the appearance can't be overridden
        return $this->projectOverridesAllowed() || $this->isSuperUser() || $this->shouldOfferOptIn($project_id);
    }

    /**
     * "Allow project-level settings to override these defaults" (default ON).
     * Never saved = null -> ON; an unticked checkbox is saved as false -> OFF.
     */
    protected function projectOverridesAllowed(): bool {
        $value = $this->getSystemSetting('allow-project-override');

        if ($value === null || $value === '') {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The project's own value for an overridable setting, or null if it has none.
     *
     * getProjectSetting() falls back to the Control Center value, and the framework deletes
     * a project value that equals the Control Center value on save. So only a value that
     * differs from the Control Center value is a genuine project choice. Returns null when
     * project overrides are switched off.
     */
    protected function getProjectOverride(string $key): ?string {
        if (!$this->projectOverridesAllowed()) {
            return null;
        }

        $project = $this->getProjectSetting($key);
        $site    = $this->getSystemSetting($key);

        if ($project === null || $project === '' || (string) $project === (string) $site) {
            return null;
        }

        // The same colour written differently (REDCap's picker saves "#f26600" for "#F26600",
        // or "#C00" vs "#cc0000") is the site colour, not a project choice. The framework's
        // exact comparison stores it anyway, so treat it as inherited here.
        if (in_array($key, ['watermark-color', 'watermark-color-picker'], true) && $this->isSameColour((string) $project, (string) $site)) {
            return null;
        }

        // The colour code box is filled in automatically when the picker changes (see
        // outputColourSyncScript()), so a project code that's just the site's colour - from the
        // site code, or the site picker when the site code is blank - is inherited too.
        if ($key === 'watermark-color') {
            $site_code   = trim((string) $this->getSystemSetting('watermark-color'));
            $site_colour = $site_code !== '' ? $site_code : (string) $this->getSystemSetting('watermark-color-picker');
            if ($this->isSameColour((string) $project, $site_colour)) {
                return null;
            }
        }

        return (string) $project;
    }

    protected function isSameColour(string $a, string $b): bool {
        $a = $this->parseColor($a);
        return $a !== null && $a === $this->parseColor($b);
    }

    /* ---------------------------------------------------------------------
     * Save-time validation
     * ------------------------------------------------------------------- */

    /**
     * Called by the framework before saving either configuration dialog. A non-null
     * return blocks the save and is shown to the user in REDCap's standard alert.
     */
    function validateSettings($settings) {
        $errors = empty($this->getProjectId())
            ? $this->validateSystemSettings($settings)
            : $this->validateProjectSettings($settings);

        if (empty($errors)) {
            return null;
        }
        return "Watermark settings not saved:\n- " . implode("\n- ", $errors);
    }

    protected function validateSystemSettings(array $settings): array {
        $errors = [];

        // Blank means "use the default" (config.json defaults aren't reliably applied)
        $min_chars = trim((string) ($settings['project-min-chars'] ?? ''));
        if ($min_chars !== '' && (!ctype_digit($min_chars) || (int) $min_chars < 1 || (int) $min_chars > self::MAX_PROJECT_MIN_CHARS)) {
            $errors[] = 'Fewest letters or digits must be a whole number from 1 to ' . self::MAX_PROJECT_MIN_CHARS . '.';
        }

        $min_font = trim((string) ($settings['project-min-font-size'] ?? ''));
        if ($min_font !== '' && (!is_numeric($min_font) || $min_font < self::MIN_FONT_PT || $min_font > self::MAX_FONT_PT)) {
            $errors[] = 'Smallest text size projects can choose must be a number from ' . self::MIN_FONT_PT . ' to ' . self::MAX_FONT_PT . '.';
        }

        $min_opacity = trim((string) ($settings['project-min-opacity'] ?? ''));
        if ($min_opacity !== '' && (!is_numeric($min_opacity) || $min_opacity < self::MIN_OPACITY || $min_opacity > 1)) {
            $errors[] = 'Lowest opacity projects can choose must be a number from ' . self::MIN_OPACITY . ' to 1.0.';
        }

        if (!empty($settings['ramp-enabled'])) {
            $start = trim((string) ($settings['ramp-start'] ?? ''));
            $max   = trim((string) ($settings['ramp-max'] ?? ''));
            if ($start === '') $start = (string) self::DEFAULT_RAMP_START;
            if ($max === '')   $max   = (string) self::DEFAULT_RAMP_MAX;

            if (!ctype_digit($start) || !ctype_digit($max)) {
                $errors[] = '"Start increasing at" and "Reach maximum at" must be whole numbers.';
            } elseif ((int) $max <= (int) $start) {
                $errors[] = '"Reach maximum at" must be greater than "Start increasing at".';
            }
        }

        return $errors;
    }

    /**
     * Check only the values the project has changed from the Control Center value -
     * unchanged values are inherited site defaults, which are always trusted.
     */
    protected function validateProjectSettings(array $settings): array {
        // Project values are ignored when overrides are off, so there's nothing to protect
        if (!$this->projectOverridesAllowed()) {
            return [];
        }

        $changed = function (string $key) use ($settings): ?string {
            $value = $settings[$key] ?? null;
            if ($value === null || $value === '' || (string) $value === (string) $this->getSystemSetting($key)) {
                return null;
            }
            return (string) $value;
        };

        $checks = [
            'watermark-text'         => fn($v) => $this->projectTextProblem($v),
            'watermark-color'        => fn($v) => $this->projectColorProblem($v),
            'watermark-color-picker' => fn($v) => $this->projectColorProblem($v),
            'watermark-opacity'      => fn($v) => $this->projectOpacityProblem($v),
            'watermark-font-size'    => fn($v) => $this->projectFontSizeProblem($v),
        ];

        $errors = [];
        foreach ($checks as $key => $check) {
            $value = $changed($key);
            if ($value !== null && ($problem = $check($value)) !== null) {
                $errors[] = $problem;
            }
        }
        return $errors;
    }

    /* ---------------------------------------------------------------------
     * Project limit rules - shared by save-time validation and display-time fallback.
     * Each returns null if the value is acceptable, otherwise a message for the user.
     * ------------------------------------------------------------------- */

    protected function projectTextProblem(string $text): ?string {
        $min = $this->getProjectMinChars();

        if ($this->countVisibleChars($text) < $min) {
            $shown = mb_strlen($text) > 30 ? mb_substr($text, 0, 30) . '…' : $text;
            return "Watermark text needs at least {$min} letters or digits (you entered \"{$shown}\").";
        }
        return null;
    }

    protected function projectColorProblem(string $color): ?string {
        $rgb = $this->parseColor($color);

        if ($rgb === null) {
            return "Colour \"{$color}\" isn't a valid hex code or rgb() colour.";
        }
        if ($this->isTooLight($rgb)) {
            return "Colour {$color} is too light to see. Choose a darker colour.";
        }
        return null;
    }

    protected function projectOpacityProblem(string $opacity): ?string {
        $min = $this->getProjectMinOpacity();

        if (!is_numeric($opacity) || (float) $opacity < $min) {
            return "Opacity can't be lower than {$min} on this site (you entered {$opacity}).";
        }
        return null;
    }

    protected function projectFontSizeProblem(string $size): ?string {
        $min = $this->getProjectMinFontSize();

        if (!is_numeric($size) || (float) $size < $min) {
            return "Text size can't be smaller than {$min}pt on this site (you entered {$size}).";
        }
        return null;
    }

    /**
     * Count letters and digits in any language. Spaces, punctuation, symbols and
     * invisible characters (zero-width spaces etc. are format characters, so they
     * aren't letters) don't count. A few blank-rendering characters are classed as
     * letters by Unicode, so they're removed first.
     */
    protected function countVisibleChars(string $text): int {
        // Hangul fillers (U+115F, U+1160, U+3164, U+FFA0) render as blank space
        $text = preg_replace('/[\x{115F}\x{1160}\x{3164}\x{FFA0}]/u', '', $text) ?? '';

        return (int) preg_match_all('/[\p{L}\p{N}]/u', $text);
    }

    protected function getProjectMinChars(): int {
        $value = $this->getSystemSetting('project-min-chars');
        return (is_numeric($value) && (int) $value >= 1)
            ? min((int) $value, self::MAX_PROJECT_MIN_CHARS)
            : self::DEFAULT_PROJECT_MIN_CHARS;
    }

    protected function getProjectMinFontSize(): float {
        $value = $this->getSystemSetting('project-min-font-size');
        return is_numeric($value)
            ? (float) max(self::MIN_FONT_PT, min(self::MAX_FONT_PT, (float) $value))
            : (float) self::DEFAULT_PROJECT_MIN_FONT_PT;
    }

    protected function getProjectMinOpacity(): float {
        $value = $this->getSystemSetting('project-min-opacity');
        return is_numeric($value)
            ? (float) max(self::MIN_OPACITY, min(1.0, (float) $value))
            : self::DEFAULT_PROJECT_MIN_OPACITY;
    }

    /* ---------------------------------------------------------------------
     * Which projects get a watermark
     * ------------------------------------------------------------------- */

    protected function shouldDisplayWatermark($project_id) {
        // Get project status (0 = Development, 1 = Production, 2 = Analysis/Cleanup)
        $project_status = $this->getProjectStatus($project_id);

        // Display watermark only for Development (0), and only for projects in scope
        return $project_status === 0 && $this->isProjectInScope($project_id);
    }

    protected function getProjectStatus($project_id) {
        global $Proj;

        // If we're in a project context and it's the current project
        if (defined('PROJECT_ID') && PROJECT_ID == $project_id && isset($Proj)) {
            return (int) $Proj->project['status'];
        }

        // Default to Development (show watermark) if we can't get status
        return 0;
    }

    /**
     * System-level scope. "New projects only" narrows "Enable module on all projects by
     * default" to projects created after the cut-off stamped by
     * redcap_module_save_configuration(). When the module is enabled project by project,
     * the admin has already chosen the projects, so every enabled project is in scope.
     *
     * Existing projects can still opt in: a project where the module has been enabled
     * directly (its own "enabled" value, rather than inheriting the system-wide one) is
     * always in scope.
     */
    protected function isProjectInScope($project_id): bool {
        if (!$this->isNewProjectsOnly()) {
            return true;
        }

        // Older projects can opt in, by enabling the module on the project itself or by
        // ticking "Show the watermark on this project" in their Configure dialog
        if ($this->isEnabledDirectlyOnProject($project_id) || $this->hasOptedIn()) {
            return true;
        }

        return $this->isCreatedAfterCutoff($project_id);
    }

    /**
     * "New projects only" is in force: chosen, and the module is enabled on all projects.
     * Under "All projects" every project is in scope, so the opt-in doesn't apply.
     */
    protected function isNewProjectsOnly(): bool {
        return $this->isEnabledOnAllProjects() && $this->getSystemSetting('apply-to') === 'new';
    }

    protected function hasOptedIn(): bool {
        return filter_var($this->getProjectSetting('opt-in'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Offer the "Show the watermark on this project" checkbox only where it does something:
     * "New projects only" is in force, and this project is excluded by the cut-off and not
     * already enabled on the project itself.
     */
    protected function shouldOfferOptIn($project_id): bool {
        return $this->isNewProjectsOnly()
            && !$this->isCreatedAfterCutoff($project_id)
            && !$this->isEnabledDirectlyOnProject($project_id);
    }

    protected function isCreatedAfterCutoff($project_id): bool {
        $cutoff = (string) $this->getSystemSetting(self::CUTOFF_SETTING);

        // "New only" chosen but no cut-off recorded (shouldn't happen) - err on the
        // side of showing the watermark rather than hiding it.
        if ($cutoff === '') {
            return true;
        }

        $created = $this->getProjectCreationTime($project_id);

        // Very old projects can have no creation time; treat them as pre-existing.
        if ($created === '') {
            return false;
        }

        return strtotime($created) >= strtotime($cutoff);
    }

    /**
     * True if the module has been enabled on this project itself. getProjectSetting('enabled')
     * can't be used, as it falls back to the system-wide value, so read the project's own
     * value only.
     */
    protected function isEnabledDirectlyOnProject($project_id): bool {
        $settings = \ExternalModules\ExternalModules::getProjectSettingsAsArray($this->PREFIX, $project_id, false);
        return filter_var($settings['enabled']['value'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    protected function getProjectCreationTime($project_id): string {
        global $Proj;

        if (defined('PROJECT_ID') && PROJECT_ID == $project_id && isset($Proj)) {
            return (string) $Proj->project['creation_time'];
        }

        $project = new \Project($project_id);
        return (string) $project->project['creation_time'];
    }

    /* ---------------------------------------------------------------------
     * Resolving the values to display
     *
     * Each field is resolved on its own: the project's value if it passes the project
     * limits, else the Control Center value, else the built-in default. So a project
     * with good text but a too-small size keeps its text.
     * ------------------------------------------------------------------- */

    /**
     * @param int  $project_id
     * @param bool $is_survey  Whether we are rendering inside a survey page.
     * @return array           Keys: 'text', 'rgb' ([r, g, b]), 'opacity', 'font_pt'.
     */
    protected function getWatermarkSettings($project_id, bool $is_survey = false): array {
        $opacity = $this->resolveOpacity();

        // Optionally raise opacity as the development project accumulates records
        $opacity = $this->applyRecordCountRamp($project_id, $opacity);

        $text = $this->resolveText();

        return [
            'text'    => $text,
            // Visible length, used to shrink long text to fit small screens
            'chars'   => max(1, mb_strlen(stripslashes($text))),
            'rgb'     => $this->resolveColor($is_survey),
            'opacity' => $opacity,
            'font_pt' => $this->resolveFontSize(),
        ];
    }

    protected function resolveText(): string {
        $project = $this->getProjectOverride('watermark-text');

        if ($project !== null && $this->projectTextProblem($project) === null) {
            $text = $project;
        } else {
            // Control Center text is trusted, but must contain at least one non-whitespace character
            $text = (string) $this->getSystemSetting('watermark-text');
            if (trim($text) === '') {
                $text = self::DEFAULT_TEXT;
            }
        }

        // Sanitise for use inside a CSS content string (escape backslashes and quotes)
        return addslashes(strip_tags($text));
    }

    /**
     * Order: project code -> project picker -> Control Center code -> Control Center picker
     * -> built-in orange. The typed code (hex or rgb()) wins over the picker at each level.
     * Anything invalid, or too light to see outside a survey, is skipped.
     */
    protected function resolveColor(bool $is_survey): array {
        $candidates = [
            $this->getProjectOverride('watermark-color'),
            $this->getProjectOverride('watermark-color-picker'),
            (string) $this->getSystemSetting('watermark-color'),
            (string) $this->getSystemSetting('watermark-color-picker'),
        ];

        foreach ($candidates as $candidate) {
            $rgb = $this->parseColor((string) $candidate);

            if ($rgb === null) {
                continue;
            }
            // Very light colours are only permitted on surveys
            if (!$is_survey && $this->isTooLight($rgb)) {
                continue;
            }
            return $rgb;
        }

        return $this->parseColor(self::DEFAULT_COLOR);
    }

    protected function resolveOpacity(): float {
        $project = $this->getProjectOverride('watermark-opacity');

        if ($project !== null && $this->projectOpacityProblem($project) === null) {
            return min(1.0, (float) $project);
        }

        $site = $this->getSystemSetting('watermark-opacity');
        $site = is_numeric($site) ? (float) $site : self::DEFAULT_OPACITY;

        // Clamp: minimum 0.1, maximum 1.0
        return max(self::MIN_OPACITY, min(1.0, $site));
    }

    protected function resolveFontSize(): float {
        $project = $this->getProjectOverride('watermark-font-size');

        if ($project !== null && $this->projectFontSizeProblem($project) === null) {
            return (float) min(self::MAX_FONT_PT, (float) $project);
        }

        $site = $this->getSystemSetting('watermark-font-size');
        $site = is_numeric($site) ? (float) $site : self::DEFAULT_FONT_PT;

        return (float) max(self::MIN_FONT_PT, min(self::MAX_FONT_PT, $site));
    }

    /**
     * Raise opacity linearly between the system "start" and "maximum" record counts.
     *
     * At or below start: project opacity unchanged.
     * At or above max:   min(1.0, project opacity x RAMP_MULTIPLIER).
     * e.g. base 0.1, start 20, max 30 -> 20: 0.10, 25: 0.30, 30+: 0.50.
     */
    protected function applyRecordCountRamp($project_id, float $base): float {
        if (!$this->getSystemSetting('ramp-enabled')) {
            return $base;
        }

        $start = $this->getSystemSetting('ramp-start');
        $max   = $this->getSystemSetting('ramp-max');

        // Blank means default; fall back to defaults if the saved thresholds are unusable
        if ($start === null || $start === '') $start = self::DEFAULT_RAMP_START;
        if ($max === null || $max === '')     $max   = self::DEFAULT_RAMP_MAX;
        if (!is_numeric($start) || !is_numeric($max) || (int) $max <= (int) $start) {
            $start = self::DEFAULT_RAMP_START;
            $max   = self::DEFAULT_RAMP_MAX;
        }
        $start = (int) $start;
        $max   = (int) $max;

        // Uses REDCap's cached record count, so this is cheap on every page load
        $count = \Records::getRecordCount($project_id);
        if (!is_numeric($count)) {
            return $base;
        }

        $ceiling  = min(1.0, $base * self::RAMP_MULTIPLIER);
        $fraction = max(0.0, min(1.0, ($count - $start) / ($max - $start)));

        return round($base + ($ceiling - $base) * $fraction, 3);
    }

    /* ---------------------------------------------------------------------
     * Colour helpers
     * ------------------------------------------------------------------- */

    /**
     * Parse a hex (#abc / #aabbcc), rgb(r,g,b) or rgba(r,g,b,a) colour into [r, g, b].
     * Any alpha component is ignored - the opacity setting controls transparency.
     * Returns null if the value isn't a recognised colour.
     */
    protected function parseColor(string $color): ?array {
        $color = strtolower(trim($color));

        if ($color === 'white') {
            return [255, 255, 255];
        }

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color, $m)) {
            $hex = $m[1];

            // Expand shorthand (#abc → #aabbcc)
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            return [
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2)),
            ];
        }

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(,\s*(0|1|0?\.\d+|1\.0+)\s*)?\)$/', $color, $m)) {
            $rgb = [(int) $m[1], (int) $m[2], (int) $m[3]];

            foreach ($rgb as $channel) {
                if ($channel > 255) {
                    return null;
                }
            }
            return $rgb;
        }

        return null;
    }

    /**
     * True if the colour is too light to see on a white page: WCAG contrast ratio
     * against white below MIN_CONTRAST_VS_WHITE. Covers white itself, pale greys,
     * and very bright colours such as yellow, cyan and lime green.
     */
    protected function isTooLight(array $rgb): bool {
        return $this->contrastWithWhite($rgb) < self::MIN_CONTRAST_VS_WHITE;
    }

    /**
     * WCAG 2 contrast ratio between the colour and white (1.0 = identical, 21 = black).
     */
    protected function contrastWithWhite(array $rgb): float {
        $linear = array_map(function ($channel) {
            $c = $channel / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        $luminance = 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];

        return 1.05 / ($luminance + 0.05);
    }

    /* ---------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------- */

    /**
     * Largest font size (in vmin) at which text of this many characters still fits on the
     * screen when drawn at 45°. Its horizontal and vertical extent is width × 0.707, and bold
     * capitals average about 0.7em wide, so fitting 90% of the shorter screen side gives
     * 0.9 / (0.707 × 0.7 × chars) ≈ 182 / chars vmin. e.g. TEST DATA ONLY (14) → 13vmin,
     * about 75pt on a 768px tablet; on desktops the size setting is usually smaller anyway.
     */
    protected function fitToScreenVmin(int $chars): float {
        return round(self::FIT_TO_SCREEN_FACTOR / max(1, $chars), 2);
    }

    /**
     * Output the watermark <style> block (plus, outside surveys, the positioning script).
     *
     * @param int  $project_id
     * @param bool $is_survey  Pass true when rendering on a survey page.
     */
    function displayWatermark($project_id, bool $is_survey = false) {
        $settings = $this->getWatermarkSettings($project_id, $is_survey);

        [$r, $g, $b] = $settings['rgb'];
        $rgba = "rgba($r, $g, $b, {$settings['opacity']})";

        // Surveys are a centred column, so centre the watermark in the window. Other pages
        // centre it over the content beside REDCap's left menu (set by the script below);
        // 40% of the window is the fallback if the script doesn't run.
        $left = $is_survey ? '50%' : 'var(--wm-center-x, 40%)';

        // The size setting is a maximum: the text shrinks to fit the space it's drawn in.
        // --wm-avail (set by the script below) is the smaller of the content width beside the
        // menu and the window height; without the script (e.g. surveys) the whole screen is used.
        // The plain pt line is a fallback for browsers without CSS min().
        $fit_ratio = round($this->fitToScreenVmin($settings['chars']) / 100, 4);

        // Vertically: halfway down the window, as in v1.0, but no lower than MAX_CENTRE_Y_PX.
        // REDCap forms and surveys start at the top of the page, so on a tall window "halfway"
        // would sit below most of the content. Up to ~900px tall windows this is unchanged.
        $max_centre_y = self::MAX_CENTRE_Y_PX;

        echo <<<CSS
        <style>
        body::before {
            content: "{$settings['text']}";
            position: fixed;
            top: 50%;
            top: min(50%, {$max_centre_y}px);
            left: {$left};
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: {$settings['font_pt']}pt;
            font-size: min({$settings['font_pt']}pt, calc(var(--wm-avail, 100vmin) * {$fit_ratio}));
            font-weight: bold;
            color: {$rgba};
            z-index: 9999;
            pointer-events: none;
            user-select: none;
            white-space: nowrap;
        }
        </style>
        CSS;

        if (!$is_survey) {
            $this->outputContentPositionScript();
        }
    }

    /**
     * Centre the watermark over the page content rather than the window. REDCap's forms and
     * dashboards sit against the left menu (#west), so on a wide screen 40% of the window is
     * well to the right of them. Sets --wm-center-x to the right edge of the menu plus half
     * the content width (capped at CONTENT_WIDTH_PX), and --wm-avail to the space the text
     * must fit in. On tablets/phones the menu is collapsed or shown as an overlay, so it's
     * ignored and the watermark centres in the window.
     */
    protected function outputContentPositionScript(): void {
        $max_width = self::CONTENT_WIDTH_PX;

        echo <<<JS
        <script>
        (function () {
            function place() {
                var west = document.getElementById('west');
                var edge = 0;
                if (west) {
                    var r = west.getBoundingClientRect();
                    // Only a visible menu docked on the left counts (not a collapsed or overlay menu)
                    if (r.width > 0 && r.right > 0 && r.right < window.innerWidth * 0.5) {
                        edge = r.right;
                    }
                }
                var root = document.documentElement.style;
                var span = Math.min(window.innerWidth - edge, {$max_width});
                root.setProperty('--wm-center-x', Math.round(edge + span / 2) + 'px');
                // Space the text must fit in: beside the menu, and the window height
                root.setProperty('--wm-avail', Math.round(Math.min(window.innerWidth - edge, window.innerHeight)) + 'px');
            }
            // The menu may not exist yet when this runs, so re-measure once the page is built
            document.addEventListener('DOMContentLoaded', place);
            window.addEventListener('load', place);
            window.addEventListener('resize', place);
        })();
        </script>
        JS;
    }
}
