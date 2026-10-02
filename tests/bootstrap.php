<?php
/**
 * PHPUnit bootstrap: stand-ins for the REDCap / External Module framework classes the
 * module touches, so the module's logic can be tested without a REDCap install or database.
 *
 * Settings are held in two arrays ($sys = Control Center, $proj = current project) and
 * getProjectSetting() mirrors the framework: project value if set, else Control Center value.
 *
 * These stubs are only loaded by PHPUnit - REDCap never includes this file.
 */

namespace ExternalModules {
    // Defined unconditionally (and before any autoloader runs) so the real framework class,
    // which needs a full REDCap bootstrap, is never loaded.
    abstract class AbstractExternalModule {
        public $PREFIX = 'watermark_for_develpment_databases';
        public array $sys = [];
        public array $proj = [];
        public bool $superUser = false;
        public $projectId = null;

        public function getSystemSetting($key) { return $this->sys[$key] ?? null; }
        public function setSystemSetting($key, $value) { $this->sys[$key] = $value; }
        public function removeSystemSetting($key) { unset($this->sys[$key]); }
        public function getProjectSetting($key) { return $this->proj[$key] ?? $this->getSystemSetting($key); }
        public function removeProjectSetting($key) { unset($this->proj[$key]); }
        public function isSuperUser() { return $this->superUser; }
        public function getProjectId() { return $this->projectId; }
        public function validateSettings($settings) { return null; }
        public function redcap_module_configure_button_display() { return true; }
    }

    // Static framework helpers the module calls directly
    class ExternalModules {
        // Project-only settings by project ID, e.g. [16 => ['enabled' => true]]
        public static array $projectOnly = [];
        // Translated strings; empty means "not translated" so the module uses its English fallback
        public static array $strings = [];

        public static function getProjectSettingsAsArray($prefix, $projectId, $includeSystemSettings = true) {
            $settings = [];
            foreach (self::$projectOnly[$projectId] ?? [] as $key => $value) {
                $settings[$key] = ['value' => $value];
            }
            return $settings;
        }

        public static function tt($key) { return self::$strings[$key] ?? null; }
    }
}

namespace {
    class Records {
        public static $count = 0;
        public static function getRecordCount($project_id) { return self::$count; }
    }

    class Project {
        public $project = ['status' => 0, 'creation_time' => '2026-01-01 00:00:00'];
    }

    define('PROJECT_ID', 1);
    define('NOW', '2026-10-02 12:00:00');
    define('PAGE', 'DataEntry/index.php');

    // The module reads the current project from the global $Proj, as REDCap provides it
    $GLOBALS['Proj'] = new Project();

    require_once __DIR__ . '/../WatermarkForDevelopmentDatabases.php';
}
