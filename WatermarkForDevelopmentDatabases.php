<?php

namespace HMRI\WatermarkForDevelopmentDatabases;

use ExternalModules\AbstractExternalModule;

class WatermarkForDevelopmentDatabases extends AbstractExternalModule {

    // Defaults
    const DEFAULT_TEXT    = 'TEST DATA ONLY';
    const DEFAULT_COLOR   = '#F26600';
    const DEFAULT_OPACITY = 0.2;
    const MIN_OPACITY     = 0.1;

    // For data entry forms
    function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        if ($this->shouldDisplayWatermark($project_id)) {
            $this->displayWatermark();
        }
    }

    // For record status dashboard, add/edit records page, and survey distribution
    function redcap_every_page_top($project_id) {
        $page = PAGE;

        if (($page === 'DataEntry/record_status_dashboard.php' ||
             $page === 'DataEntry/record_home.php' ||
             $page === 'Surveys/invite_participants.php') &&
            $this->shouldDisplayWatermark($project_id)) {
            $this->displayWatermark();
        }
    }

    // For surveys
    function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        if ($this->shouldDisplayWatermark($project_id)) {
            $this->displayWatermark(true);
        }
    }

    protected function shouldDisplayWatermark($project_id) {
        // Get project status (0 = Development, 1 = Production, 2 = Analysis/Cleanup)
        $project_status = $this->getProjectStatus($project_id);

        // Display watermark only for Development (0)
        return $project_status === 0;
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
     * Retrieve and validate all watermark settings.
     *
     * @param bool $is_survey  Whether we are rendering inside a survey page.
     * @return array           Associative array with keys 'text', 'color', 'opacity'.
     */
    protected function getWatermarkSettings(bool $is_survey = false): array {
        // --- Text ---
        $text = (string) $this->getProjectSetting('watermark-text');

        // Must contain at least one non-whitespace character
        if (trim($text) === '') {
            $text = self::DEFAULT_TEXT;
        }

        // Sanitise for use inside a CSS content string (escape backslashes and quotes)
        $text = addslashes(strip_tags($text));

        // --- Colour ---
        $color = trim((string) $this->getProjectSetting('watermark-color'));

        if (!$this->isValidHexColor($color)) {
            $color = self::DEFAULT_COLOR;
        }

        // White is not permitted outside of surveys
        if (!$is_survey && $this->isWhiteColor($color)) {
            $color = self::DEFAULT_COLOR;
        }

        // --- Opacity ---
        $opacity = $this->getProjectSetting('watermark-opacity');
        $opacity = ($opacity !== null && $opacity !== '') ? (float) $opacity : self::DEFAULT_OPACITY;

        // Clamp: minimum 0.1, maximum 1.0
        $opacity = max(self::MIN_OPACITY, min(1.0, $opacity));

        return [
            'text'    => $text,
            'color'   => $color,
            'opacity' => $opacity,
        ];
    }

    /**
     * Returns true if $color is a valid 3- or 6-digit hex colour code.
     */
    protected function isValidHexColor(string $color): bool {
        return (bool) preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $color);
    }

    /**
     * Returns true if $color resolves to pure white in any common notation.
     */
    protected function isWhiteColor(string $color): bool {
        $normalised = strtolower(trim($color));
        return in_array($normalised, ['#fff', '#ffffff', 'white'], true);
    }

    /**
     * Convert a 3- or 6-digit hex colour to an rgba() CSS value.
     */
    protected function hexToRgba(string $hex, float $opacity): string {
        $hex = ltrim($hex, '#');

        // Expand shorthand (#abc → #aabbcc)
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return "rgba($r, $g, $b, $opacity)";
    }

    /**
     * Output the watermark <style> block.
     *
     * @param bool $is_survey  Pass true when rendering on a survey page.
     */
    function displayWatermark(bool $is_survey = false) {
        $settings = $this->getWatermarkSettings($is_survey);

        $rgba = $this->hexToRgba($settings['color'], $settings['opacity']);

        // Survey pages are full-width, so centre the watermark exactly.
        // Data-entry pages have a left-hand nav panel, so shift the
        // horizontal anchor slightly to the right (matching original CSS).
        $left = $is_survey ? '50%' : '40%';

        echo <<<CSS
        <style>
        body::before {
            content: "{$settings['text']}";
            position: fixed;
            top: 50%;
            left: {$left};
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 120px;
            font-weight: bold;
            color: {$rgba};
            z-index: 9999;
            pointer-events: none;
            user-select: none;
            white-space: nowrap;
        }
        </style>
        CSS;
    }
}
