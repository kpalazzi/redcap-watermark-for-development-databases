<?php

// Namespaced as required by the REDCap module scanner (no definitions in the global space)
namespace HMRI\WatermarkForDevelopmentDatabases\Tests;

use HMRI\WatermarkForDevelopmentDatabases\WatermarkForDevelopmentDatabases;
use PHPUnit\Framework\TestCase;
use Project;           // stand-in from tests/bootstrap.php (REDCap's global class)
use Records;           // stand-in from tests/bootstrap.php (REDCap's global class)
use ReflectionMethod;

/**
 * Unit tests for the watermark module's decision logic.
 *
 * Priority is on paths where a silent mistake would hide the watermark (or show the
 * wrong one) on a development project: project limits, fallback order, the
 * "new projects only" cut-off and the record-count ramp.
 */
class WatermarkForDevelopmentDatabasesTest extends TestCase {

    private WatermarkForDevelopmentDatabases $module;

    protected function setUp(): void {
        $this->module = new WatermarkForDevelopmentDatabases();
        Records::$count = 0;
        \ExternalModules\ExternalModules::$projectOnly = [];
        \ExternalModules\ExternalModules::$strings = [];
        $GLOBALS['Proj'] = new Project();
    }

    /** Call a protected method on the module. */
    private function call(string $method, ...$args) {
        $reflection = new ReflectionMethod($this->module, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->module, ...$args);
    }

    private function settings(bool $isSurvey = false): array {
        return $this->call('getWatermarkSettings', 1, $isSurvey);
    }

    /** Control Center values as seeded on install, with optional changes. */
    private function site(array $overrides = []): void {
        $this->module->sys = array_merge([
            'allow-project-override' => true,
            'watermark-text'         => 'TEST DATA ONLY',
            'watermark-color-picker' => '#F26600',
            'watermark-opacity'      => '0.1',
            'watermark-font-size'    => '90',
            'project-min-chars'      => '4',
            'project-min-font-size'  => '48',
            'project-min-opacity'    => '0.1',
        ], $overrides);
    }

    /* ------------------------------------------------------------------
     * Colour parsing and lightness
     * ---------------------------------------------------------------- */

    /** @dataProvider colorParseCases */
    public function testParseColor(string $input, ?array $expected): void {
        $this->assertSame($expected, $this->call('parseColor', $input));
    }

    public function colorParseCases(): array {
        return [
            '6-digit hex'          => ['#F26600', [242, 102, 0]],
            '3-digit hex'          => ['#c00', [204, 0, 0]],
            'surrounding spaces'   => ['  #c00 ', [204, 0, 0]],
            'rgb()'                => ['rgb(242, 102, 0)', [242, 102, 0]],
            'rgba() alpha ignored' => ['rgba(242,102,0,0.5)', [242, 102, 0]],
            'white keyword'        => ['white', [255, 255, 255]],
            'channel over 255'     => ['rgb(256,0,0)', null],
            'hex without #'        => ['F26600', null],
            '4-digit hex'          => ['#F266', null],
            'colour name'          => ['orange', null],
            'empty'                => ['', null],
        ];
    }

    /** @dataProvider lightnessCases */
    public function testIsTooLight(string $color, bool $tooLight): void {
        $rgb = $this->call('parseColor', $color);
        $this->assertSame($tooLight, $this->call('isTooLight', $rgb), $color);
    }

    public function lightnessCases(): array {
        return [
            'white'           => ['#FFFFFF', true],
            'near-white'      => ['#FAFAFA', true],
            'pale grey #DDD'  => ['#DDD', true],
            'yellow'          => ['#FFFF00', true],
            'cyan'            => ['#00FFFF', true],
            'lime'            => ['#00FF00', true],
            'grey #CCC'       => ['#CCC', false],
            'grey #BBB'       => ['#BBB', false],
            'default orange'  => ['#F26600', false],
            'red'             => ['#C00', false],
            'black'           => ['#000', false],
        ];
    }

    /* ------------------------------------------------------------------
     * Text: counting letters and digits
     * ---------------------------------------------------------------- */

    /** @dataProvider visibleCharCases */
    public function testCountVisibleChars(string $text, int $expected): void {
        $this->assertSame($expected, $this->call('countVisibleChars', $text));
    }

    public function visibleCharCases(): array {
        return [
            'default text'          => ['TEST DATA ONLY', 12],
            'single dot'            => ['.', 0],
            'dashes and spaces'     => ['- - -', 0],
            'single letter'         => ['A', 1],
            'digits count'          => ['DEV 2', 4],
            'zero-width spaces'     => ["\u{200B}\u{200B}\u{200B}\u{200B}", 0],
            'non-breaking spaces'   => ["\u{00A0}\u{00A0}\u{00A0}\u{00A0}", 0],
            'braille blank'         => ["\u{2800}\u{2800}\u{2800}\u{2800}", 0],
            'hangul filler'         => ["\u{3164}\u{3164}\u{3164}\u{3164}", 0],
            'zero-width between'    => ["A\u{200B}B\u{200B}", 2],
            'accented letters'      => ['ÉTUDE', 5],
            'non-Latin script'      => ['测试数据', 4],
        ];
    }

    public function testProjectTextProblemUsesSiteMinimum(): void {
        $this->site(['project-min-chars' => '4']);
        $this->assertNull($this->call('projectTextProblem', 'TEST'));
        $this->assertNotNull($this->call('projectTextProblem', 'ABC'));

        $this->site(['project-min-chars' => '2']);
        $this->assertNull($this->call('projectTextProblem', 'AB'));
    }

    public function testInvalidSiteMinimumFallsBackToDefault(): void {
        $this->site(['project-min-chars' => 'abc']);
        $this->assertSame(4, $this->call('getProjectMinChars'));

        $this->site(['project-min-font-size' => '5']);
        $this->assertSame(10.0, $this->call('getProjectMinFontSize'), 'clamped to the 10pt floor');

        $this->site(['project-min-opacity' => '0.01']);
        $this->assertSame(0.1, $this->call('getProjectMinOpacity'), 'clamped to the 0.1 floor');
    }

    /* ------------------------------------------------------------------
     * Resolving what's displayed
     * ---------------------------------------------------------------- */

    public function testNothingSavedUsesBuiltInDefaults(): void {
        $s = $this->settings();
        $this->assertSame('TEST DATA ONLY', $s['text']);
        $this->assertSame([242, 102, 0], $s['rgb']);
        $this->assertSame(0.1, $s['opacity']);
        $this->assertSame(90.0, $s['font_pt']);
    }

    public function testSiteValuesUsedWhenProjectHasNone(): void {
        $this->site(['watermark-text' => 'TRAINING', 'watermark-color-picker' => '#0000FF',
                     'watermark-opacity' => '0.4', 'watermark-font-size' => '20']);
        $s = $this->settings();
        $this->assertSame('TRAINING', $s['text']);
        $this->assertSame([0, 0, 255], $s['rgb']);
        $this->assertSame(0.4, $s['opacity']);
        $this->assertSame(20.0, $s['font_pt'], 'site values are trusted below the project minimum');
    }

    public function testSiteTextIsTrustedEvenIfShort(): void {
        $this->site(['watermark-text' => 'X']);
        $this->assertSame('X', $this->settings()['text']);
    }

    public function testBlankSiteTextFallsBackToBuiltIn(): void {
        $this->site(['watermark-text' => '   ']);
        $this->assertSame('TEST DATA ONLY', $this->settings()['text']);
    }

    public function testValidProjectOverridesAreUsed(): void {
        $this->site();
        $this->module->proj = ['watermark-text' => 'PROJECT TRAINING', 'watermark-opacity' => '0.3',
                               'watermark-font-size' => '60', 'watermark-color-picker' => '#0000FF'];
        $s = $this->settings();
        $this->assertSame('PROJECT TRAINING', $s['text']);
        $this->assertSame(0.3, $s['opacity']);
        $this->assertSame(60.0, $s['font_pt']);
        $this->assertSame([0, 0, 255], $s['rgb']);
    }

    /** @dataProvider invalidProjectValueCases */
    public function testInvalidProjectValueFallsBackToSiteForThatFieldOnly(string $key, string $bad, string $field, $siteResult): void {
        $this->site(['watermark-text' => 'SITE TEXT', 'watermark-opacity' => '0.2', 'watermark-font-size' => '80',
                     'watermark-color-picker' => '#0000FF']);
        // One bad value alongside a good project text
        $this->module->proj = [$key => $bad];
        if ($key !== 'watermark-text') {
            $this->module->proj['watermark-text'] = 'GOOD TEXT';
        }

        $s = $this->settings();
        $this->assertSame($siteResult, $s[$field]);
        if ($key !== 'watermark-text') {
            $this->assertSame('GOOD TEXT', $s['text'], 'other fields keep their project values');
        }
    }

    public function invalidProjectValueCases(): array {
        return [
            'dot as text'          => ['watermark-text', '.', 'text', 'SITE TEXT'],
            'zero-width text'      => ['watermark-text', "\u{200B}\u{200B}\u{200B}\u{200B}", 'text', 'SITE TEXT'],
            'opacity below min'    => ['watermark-opacity', '0.05', 'opacity', 0.2],
            'opacity not a number' => ['watermark-opacity', 'abc', 'opacity', 0.2],
            'font below min'       => ['watermark-font-size', '12', 'font_pt', 80.0],
            'font not a number'    => ['watermark-font-size', 'big', 'font_pt', 80.0],
            'near-white picker'    => ['watermark-color-picker', '#FAFAFA', 'rgb', [0, 0, 255]],
            'yellow code'          => ['watermark-color', '#FFFF00', 'rgb', [0, 0, 255]],
            'junk code'            => ['watermark-color', 'junk', 'rgb', [0, 0, 255]],
        ];
    }

    public function testProjectValuesAboveMaximumAreCapped(): void {
        $this->site();
        $this->module->proj = ['watermark-opacity' => '3', 'watermark-font-size' => '999'];
        $s = $this->settings();
        $this->assertSame(1.0, $s['opacity']);
        $this->assertSame(300.0, $s['font_pt']);
    }

    public function testProjectValuesIgnoredWhenOverridesOff(): void {
        $this->site(['allow-project-override' => false, 'watermark-text' => 'SITE TEXT']);
        $this->module->proj = ['watermark-text' => 'PROJECT TEXT', 'watermark-opacity' => '0.9',
                               'watermark-color' => '#000000'];
        $s = $this->settings();
        $this->assertSame('SITE TEXT', $s['text']);
        $this->assertSame(0.1, $s['opacity']);
        $this->assertSame([242, 102, 0], $s['rgb']);
    }

    /** @dataProvider overrideFlagCases */
    public function testProjectOverridesAllowed($stored, bool $expected): void {
        $this->module->sys = $stored === 'unset' ? [] : ['allow-project-override' => $stored];
        $this->assertSame($expected, $this->call('projectOverridesAllowed'));
    }

    public function overrideFlagCases(): array {
        return [
            'never saved defaults to on' => ['unset', true],
            'true'                       => [true, true],
            'false (unticked)'           => [false, false],
            'string "false"'             => ['false', false],
            'string "1"'                 => ['1', true],
        ];
    }

    /* ------------------------------------------------------------------
     * Colour fallback order
     * ---------------------------------------------------------------- */

    public function testProjectPickerBeatsInheritedSiteCode(): void {
        $this->site(['watermark-color' => '#C00', 'watermark-color-picker' => '#0000FF']);
        $this->module->proj = ['watermark-color-picker' => '#008000'];
        $this->assertSame([0, 128, 0], $this->settings()['rgb']);
    }

    public function testProjectCodeBeatsProjectPicker(): void {
        $this->site();
        $this->module->proj = ['watermark-color' => 'rgb(1,2,3)', 'watermark-color-picker' => '#008000'];
        $this->assertSame([1, 2, 3], $this->settings()['rgb']);
    }

    public function testSiteCodeBeatsSitePicker(): void {
        $this->site(['watermark-color' => '#C00', 'watermark-color-picker' => '#0000FF']);
        $this->assertSame([204, 0, 0], $this->settings()['rgb']);
    }

    public function testInvalidSiteCodeFallsToSitePicker(): void {
        $this->site(['watermark-color' => 'junk', 'watermark-color-picker' => '#0000FF']);
        $this->assertSame([0, 0, 255], $this->settings()['rgb']);
    }

    public function testTooLightSiteColourBlockedOnDataEntryButAllowedOnSurvey(): void {
        $this->site(['watermark-color' => '#FFF', 'watermark-color-picker' => '#FFF']);
        $this->assertSame([242, 102, 0], $this->settings(false)['rgb']);
        $this->assertSame([255, 255, 255], $this->settings(true)['rgb']);
    }

    /** @dataProvider sameColourCases */
    public function testSameColourWrittenDifferentlyStillFollowsSite(string $projectPicker): void {
        // REDCap's picker saves lowercase; the project must keep following the site colour
        $this->site(['watermark-color-picker' => '#F26600', 'watermark-color' => '#C00']);
        $this->module->proj = ['watermark-color-picker' => $projectPicker];
        $this->assertNull($this->call('getProjectOverride', 'watermark-color-picker'));

        // So the site code still wins, as it would with no project value at all
        $this->assertSame([204, 0, 0], $this->settings()['rgb']);
    }

    public function sameColourCases(): array {
        return [
            'lowercase hex' => ['#f26600'],
            'rgb() form'    => ['rgb(242, 102, 0)'],
        ];
    }

    public function testAutoFilledCodeMatchingSiteColourFollowsSite(): void {
        // PID 29's case: site code blank, site picker orange; the sync filled the project code with the same orange
        $this->site(['watermark-color-picker' => '#F26600']);
        $this->module->proj = ['watermark-color' => '#F26600', 'watermark-color-picker' => '#f26600'];
        $this->assertNull($this->call('getProjectOverride', 'watermark-color'));
        $this->assertNull($this->call('getProjectOverride', 'watermark-color-picker'));

        // Saving the project dialog removes the copies of the site colour...
        $this->module->redcap_module_save_configuration(1);
        $this->assertSame([], $this->module->proj);

        // ...so when the admin later changes the site colour, the project follows it
        $this->module->sys['watermark-color-picker'] = '#1F4E79';
        $this->assertSame([31, 78, 121], $this->settings()['rgb']);
    }

    public function testProjectSaveKeepsGenuineColourChoices(): void {
        $this->site(['watermark-color-picker' => '#F26600']);
        $this->module->proj = ['watermark-color' => '#1F4E79', 'watermark-color-picker' => '#1f4e79', 'watermark-text' => 'MINE'];
        $this->module->redcap_module_save_configuration(1);
        $this->assertSame(['watermark-color' => '#1F4E79', 'watermark-color-picker' => '#1f4e79', 'watermark-text' => 'MINE'], $this->module->proj);
    }

    public function testProjectSaveLeavesColoursAloneWhenOverridesOff(): void {
        $this->site(['watermark-color-picker' => '#F26600', 'allow-project-override' => false]);
        $this->module->proj = ['watermark-color' => '#F26600'];
        $this->module->redcap_module_save_configuration(1);
        $this->assertSame(['watermark-color' => '#F26600'], $this->module->proj, 'kept for if overrides are switched back on');
    }

    public function testLegacyOrangeCodeFollowsOrangeSite(): void {
        // A v1.0.0 project's saved "#F26600" now matches the site orange, so it follows the site.
        // It only stays an override if the site colour is something else.
        $this->site(['watermark-color-picker' => '#F26600']);
        $this->module->proj = ['watermark-color' => '#F26600'];
        $this->assertNull($this->call('getProjectOverride', 'watermark-color'));
    }

    public function testDifferentProjectColourIsStillAnOverride(): void {
        $this->site(['watermark-color-picker' => '#F26600']);
        $this->module->proj = ['watermark-color-picker' => '#1f4e79'];
        $this->assertSame('#1f4e79', $this->call('getProjectOverride', 'watermark-color-picker'));
    }

    public function testLegacyProjectCodeIsKept(): void {
        // v1.0.0 projects saved #F26600 in the code box; with no cleanup it stays a project override
        $this->site(['watermark-color-picker' => '#0000FF']);
        $this->module->proj = ['watermark-color' => '#F26600'];
        $this->assertSame([242, 102, 0], $this->settings()['rgb']);
    }

    /* ------------------------------------------------------------------
     * Save-time validation
     * ---------------------------------------------------------------- */

    public function testProjectSaveWithUnchangedValuesPasses(): void {
        // Even if the site text would fail the project rule, unchanged values aren't checked
        $this->site(['watermark-text' => 'X']);
        $this->module->projectId = 1;
        $this->assertNull($this->module->validateSettings([
            'watermark-text' => 'X', 'watermark-opacity' => '0.1', 'watermark-font-size' => '90',
            'watermark-color-picker' => '#F26600', 'watermark-color' => '',
        ]));
    }

    public function testProjectSaveListsEveryProblem(): void {
        $this->site();
        $this->module->projectId = 1;
        $message = $this->module->validateSettings([
            'watermark-text' => '...', 'watermark-font-size' => '12', 'watermark-color' => '#FAFAFA',
            'watermark-opacity' => '0.05', 'watermark-color-picker' => 'junk',
        ]);

        $this->assertIsString($message);
        $this->assertStringStartsWith('Watermark settings not saved:', $message);
        $this->assertStringContainsString('at least 4 letters or digits', $message);
        $this->assertStringContainsString('smaller than 48pt', $message);
        $this->assertStringContainsString('#FAFAFA is too light', $message);
        $this->assertStringContainsString('lower than 0.1', $message);
        $this->assertStringContainsString("isn't a valid", $message);
    }

    public function testProjectSaveWithGoodChangesPasses(): void {
        $this->site();
        $this->module->projectId = 1;
        $this->assertNull($this->module->validateSettings([
            'watermark-text' => 'TRAINING', 'watermark-font-size' => '60', 'watermark-color' => '#C00',
            'watermark-opacity' => '0.3',
        ]));
    }

    public function testProjectSaveNotCheckedWhenOverridesOff(): void {
        $this->site(['allow-project-override' => false]);
        $this->module->projectId = 1;
        $this->assertNull($this->module->validateSettings(['watermark-text' => '.']));
    }

    /** @dataProvider systemValidationCases */
    public function testSystemSaveValidation(array $settings, bool $shouldFail): void {
        $this->module->projectId = null;
        $result = $this->module->validateSettings($settings);
        $shouldFail ? $this->assertIsString($result) : $this->assertNull($result);
    }

    public function systemValidationCases(): array {
        return [
            'all blank uses defaults'   => [['project-min-chars' => '', 'project-min-font-size' => '', 'project-min-opacity' => ''], false],
            'valid limits'              => [['project-min-chars' => '4', 'project-min-font-size' => '48', 'project-min-opacity' => '0.15'], false],
            'min chars zero'            => [['project-min-chars' => '0'], true],
            'min chars too high'        => [['project-min-chars' => '51'], true],
            'min chars decimal'         => [['project-min-chars' => '2.5'], true],
            'min font below 10'         => [['project-min-font-size' => '5'], true],
            'min font above 300'        => [['project-min-font-size' => '301'], true],
            'min opacity below 0.1'     => [['project-min-opacity' => '0.05'], true],
            'min opacity above 1'       => [['project-min-opacity' => '1.5'], true],
            'ramp ok'                   => [['ramp-enabled' => true, 'ramp-start' => '20', 'ramp-max' => '30'], false],
            'ramp blanks use defaults'  => [['ramp-enabled' => true, 'ramp-start' => '', 'ramp-max' => ''], false],
            'ramp max equals start'     => [['ramp-enabled' => true, 'ramp-start' => '30', 'ramp-max' => '30'], true],
            'ramp not whole numbers'    => [['ramp-enabled' => true, 'ramp-start' => '2.5', 'ramp-max' => '30'], true],
            'ramp off ignores junk'     => [['ramp-enabled' => false, 'ramp-start' => 'x'], false],
        ];
    }

    /* ------------------------------------------------------------------
     * Configure button
     * ---------------------------------------------------------------- */

    public function testConfigureButtonVisibility(): void {
        $this->assertTrue($this->module->redcap_module_configure_button_display(null), 'always shown in the Control Center');

        $this->site(['allow-project-override' => true]);
        $this->assertTrue($this->module->redcap_module_configure_button_display(1));

        $this->site(['allow-project-override' => false]);
        $this->assertFalse($this->module->redcap_module_configure_button_display(1), 'hidden from non-admins');

        $this->module->superUser = true;
        $this->assertTrue($this->module->redcap_module_configure_button_display(1), 'admins still see it');
    }

    /* ------------------------------------------------------------------
     * Record-count opacity ramp
     * ---------------------------------------------------------------- */

    /** @dataProvider rampCases */
    public function testRecordCountRamp(int $records, float $base, float $expected): void {
        $this->module->sys = ['ramp-enabled' => true, 'ramp-start' => '20', 'ramp-max' => '30'];
        Records::$count = $records;
        $this->assertSame($expected, $this->call('applyRecordCountRamp', 1, $base));
    }

    public function rampCases(): array {
        return [
            'no records'             => [0, 0.1, 0.1],
            'at start'               => [20, 0.1, 0.1],
            'one past start'         => [21, 0.1, 0.14],
            'halfway'                => [25, 0.1, 0.3],
            'at max'                 => [30, 0.1, 0.5],
            'well past max'          => [99, 0.1, 0.5],
            'base 0.5 halfway'       => [25, 0.5, 0.75],
            'base 0.5 capped at 1'   => [30, 0.5, 1.0],
            'base 1.0 stays 1'       => [30, 1.0, 1.0],
        ];
    }

    public function testRampOffLeavesOpacityAlone(): void {
        $this->module->sys = ['ramp-enabled' => false];
        Records::$count = 30;
        $this->assertSame(0.1, $this->call('applyRecordCountRamp', 1, 0.1));
    }

    public function testRampWithUnusableThresholdsUsesDefaults(): void {
        $this->module->sys = ['ramp-enabled' => true, 'ramp-start' => '30', 'ramp-max' => '20'];
        Records::$count = 25;
        $this->assertSame(0.3, $this->call('applyRecordCountRamp', 1, 0.1));
    }

    public function testRampAppliedToResolvedOpacity(): void {
        $this->site(['ramp-enabled' => true, 'ramp-start' => '20', 'ramp-max' => '30']);
        Records::$count = 30;
        $this->assertSame(0.5, $this->settings()['opacity']);
    }

    /* ------------------------------------------------------------------
     * "New projects only" scope
     * ---------------------------------------------------------------- */

    public function testAllProjectsInScopeByDefault(): void {
        $this->assertTrue($this->call('isProjectInScope', 1));
    }

    public function testSwitchingToNewOnlyStampsCutoffAndHidesOlderProjects(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new'];
        $this->module->redcap_module_save_configuration('');
        $this->assertSame(NOW, $this->module->sys['new-project-cutoff']);

        $GLOBALS['Proj']->project['creation_time'] = '2026-01-01 00:00:00';
        $this->assertFalse($this->call('isProjectInScope', 1));

        $GLOBALS['Proj']->project['creation_time'] = '2026-10-03 09:00:00';
        $this->assertTrue($this->call('isProjectInScope', 1));
    }

    public function testResavingKeepsExistingCutoff(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new', 'new-project-cutoff' => '2026-09-01 00:00:00'];
        $this->module->redcap_module_save_configuration('');
        $this->assertSame('2026-09-01 00:00:00', $this->module->sys['new-project-cutoff']);
    }

    public function testProjectSaveDoesNotTouchCutoff(): void {
        $this->module->sys = ['apply-to' => 'all', 'new-project-cutoff' => '2026-09-01 00:00:00'];
        $this->module->redcap_module_save_configuration(5);
        $this->assertSame('2026-09-01 00:00:00', $this->module->sys['new-project-cutoff']);
    }

    public function testSwitchingBackToAllClearsCutoff(): void {
        $this->module->sys = ['apply-to' => 'all', 'new-project-cutoff' => NOW];
        $this->module->redcap_module_save_configuration('');
        $this->assertArrayNotHasKey('new-project-cutoff', $this->module->sys);
    }

    public function testProjectWithNoCreationTimeTreatedAsExisting(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new', 'new-project-cutoff' => NOW];
        $GLOBALS['Proj']->project['creation_time'] = null;
        $this->assertFalse($this->call('isProjectInScope', 1));
    }

    public function testNewOnlyWithoutCutoffErrsTowardsShowing(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new'];
        $this->assertTrue($this->call('isProjectInScope', 1));
    }

    public function testExistingProjectEnabledDirectlyGetsWatermarkUnderNewOnly(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new', 'new-project-cutoff' => NOW];
        $GLOBALS['Proj']->project['creation_time'] = '2025-12-01 23:45:00';

        // Inherits "enabled on all projects" only: excluded, it's older than the cut-off
        $this->assertFalse($this->call('isProjectInScope', 1));

        // Enabled on the project itself: opted in
        \ExternalModules\ExternalModules::$projectOnly[1] = ['enabled' => true];
        $this->assertTrue($this->call('isProjectInScope', 1));

        // Disabled on the project itself: not opted in
        \ExternalModules\ExternalModules::$projectOnly[1] = ['enabled' => false];
        $this->assertFalse($this->call('isProjectInScope', 1));
    }

    /* ------------------------------------------------------------------
     * Opt-in for older projects ("Show the watermark on this project")
     * ---------------------------------------------------------------- */

    private function oldProjectUnderNewOnly(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new', 'new-project-cutoff' => '2026-10-02 00:31:36',
                              'allow-project-override' => true];
        $GLOBALS['Proj']->project['creation_time'] = '2026-09-10 22:20:58';
    }

    public function testOlderProjectOptsInWithCheckbox(): void {
        $this->oldProjectUnderNewOnly();
        $this->assertFalse($this->call('isProjectInScope', 1), 'off by default');

        $this->module->proj = ['opt-in' => true];
        $this->assertTrue($this->call('isProjectInScope', 1), 'ticked: on');

        $this->module->proj = ['opt-in' => false];
        $this->assertFalse($this->call('isProjectInScope', 1), 'unticked again: off');
    }

    public function testOptInOfferedOnlyToOlderProjectsUnderNewOnly(): void {
        $this->oldProjectUnderNewOnly();
        $this->assertTrue($this->call('shouldOfferOptIn', 1));

        // New project: already in scope
        $GLOBALS['Proj']->project['creation_time'] = '2026-10-03 09:00:00';
        $this->assertFalse($this->call('shouldOfferOptIn', 1));

        // "All projects": not an option
        $this->oldProjectUnderNewOnly();
        $this->module->sys['apply-to'] = 'all';
        $this->assertFalse($this->call('shouldOfferOptIn', 1));

        // Enabled project by project (box unticked): not an option
        $this->oldProjectUnderNewOnly();
        $this->module->sys['enabled'] = false;
        $this->assertFalse($this->call('shouldOfferOptIn', 1));

        // Already enabled on the project itself: not needed
        $this->oldProjectUnderNewOnly();
        \ExternalModules\ExternalModules::$projectOnly[1] = ['enabled' => true];
        $this->assertFalse($this->call('shouldOfferOptIn', 1));
    }

    public function testOptInPreTickedOnlyUntilAChoiceIsSaved(): void {
        $this->oldProjectUnderNewOnly();
        $this->assertTrue($this->call('shouldPreTickOptIn', 1), 'never saved: pre-tick');
        $this->assertFalse($this->call('isProjectInScope', 1), 'pre-ticking alone does not turn it on');

        $this->module->proj = ['opt-in' => false];
        $this->assertFalse($this->call('shouldPreTickOptIn', 1), 'saved unticked: respected');

        $this->module->proj = ['opt-in' => true];
        $this->assertFalse($this->call('shouldPreTickOptIn', 1), 'saved ticked: shown as saved');

        // Not offered at all for new projects
        $this->module->proj = [];
        $GLOBALS['Proj']->project['creation_time'] = '2026-10-03 09:00:00';
        $this->assertFalse($this->call('shouldPreTickOptIn', 1));
    }

    public function testOptInPreTickScriptMarksTheBoxSoUntickingSticks(): void {
        ob_start();
        $this->call('outputOptInPreTickScript');
        $js = ob_get_clean();
        $this->assertStringContainsString('input[name="opt-in"]', $js);
        $this->assertStringContainsString('box.dataset.wmPreTicked', $js);
    }

    public function testStaleOptInIgnoredUnderAllProjects(): void {
        // Opted out isn't possible under "All projects": every project is in scope
        $this->oldProjectUnderNewOnly();
        $this->module->sys['apply-to'] = 'all';
        $this->module->proj = ['opt-in' => false];
        $this->assertTrue($this->call('isProjectInScope', 1));
    }

    public function testOptInRowPlacedAfterRedcapOptionsOrRemoved(): void {
        $settings = [
            ['key' => 'watermark-text', 'name' => 'x'],
            ['key' => 'reserved-hide-from-non-admins-in-project-list', 'name' => 'Hide'],
            ['key' => 'opt-in', 'name' => 'Show the watermark on this project'],
        ];

        $this->oldProjectUnderNewOnly();
        $keys = array_column($this->module->redcap_module_configuration_settings(1, $settings), 'key');
        $this->assertSame(['watermark-text', 'reserved-hide-from-non-admins-in-project-list', 'opt-in'], $keys);

        $settings = [
            ['key' => 'reserved-hide-from-non-admins-in-project-list', 'name' => 'Hide'],
            ['key' => 'watermark-text', 'name' => 'x'],
            ['key' => 'opt-in', 'name' => 'Show'],
        ];
        $keys = array_column($this->module->redcap_module_configuration_settings(1, $settings), 'key');
        $this->assertSame(['reserved-hide-from-non-admins-in-project-list', 'opt-in', 'watermark-text'], $keys);

        $this->module->sys['apply-to'] = 'all';
        $keys = array_column($this->module->redcap_module_configuration_settings(1, $settings), 'key');
        $this->assertNotContains('opt-in', $keys, 'hidden under "All projects"');
    }

    public function testOptInKeptWhenOverridesOffAndButtonShown(): void {
        $this->oldProjectUnderNewOnly();
        $this->module->sys['allow-project-override'] = false;
        $settings = [
            ['key' => 'reserved-hide-from-non-admins-in-project-list', 'name' => 'Hide'],
            ['key' => 'watermark-text', 'name' => 'x'],
            ['key' => 'opt-in', 'name' => 'Show'],
        ];
        $keys = array_column($this->module->redcap_module_configuration_settings(1, $settings), 'key');
        $this->assertSame(['reserved-hide-from-non-admins-in-project-list', 'opt-in', 'appearance-locked-note'], $keys);

        // Non-admins still get the Configure button, so they can opt in
        $this->assertTrue($this->module->redcap_module_configure_button_display(1));

        $GLOBALS['Proj']->project['creation_time'] = '2026-10-03 09:00:00';
        $this->assertFalse($this->module->redcap_module_configure_button_display(1), 'new project, overrides off: hidden');
    }

    public function testNewOnlyIgnoredWhenModuleEnabledProjectByProject(): void {
        // Box unticked: the admin chose the projects, so a stale "new" setting must not hide the watermark
        $this->module->sys = ['enabled' => false, 'apply-to' => 'new', 'new-project-cutoff' => NOW];
        $GLOBALS['Proj']->project['creation_time'] = '2026-01-01 00:00:00';
        $this->assertTrue($this->call('isProjectInScope', 1));
    }

    public function testUntickingEnableOnAllClearsCutoff(): void {
        // So re-ticking later and choosing "new" stamps a fresh cut-off
        $this->module->sys = ['enabled' => false, 'apply-to' => 'new', 'new-project-cutoff' => '2026-01-01 00:00:00'];
        $this->module->redcap_module_save_configuration('');
        $this->assertArrayNotHasKey('new-project-cutoff', $this->module->sys);

        // Unticking also reset the choice to "all"; the admin re-ticks and picks "new" again
        $this->module->sys['enabled'] = true;
        $this->module->sys['apply-to'] = 'new';
        $this->module->redcap_module_save_configuration('');
        $this->assertSame(NOW, $this->module->sys['new-project-cutoff']);
    }

    public function testUntickingEnableOnAllResetsApplyToAll(): void {
        // Stale "new" saved while the box was unticked must not reappear when the box is ticked
        $this->module->sys = ['enabled' => false, 'apply-to' => 'new'];
        $this->module->redcap_module_save_configuration('');
        $this->assertSame('all', $this->module->sys['apply-to']);
    }

    public function testControlCenterPageLoadResetsStaleNewOnly(): void {
        // Values saved before the reset rule existed are fixed without the admin having to save
        $this->module->sys = ['enabled' => false, 'apply-to' => 'new', 'new-project-cutoff' => '2026-10-02 00:04:51'];
        $this->call('seedDefaultsIfControlCenterModulePage', 'manager/control_center.php', null);
        $this->assertSame('all', $this->module->sys['apply-to']);
        $this->assertArrayNotHasKey('new-project-cutoff', $this->module->sys);
    }

    public function testControlCenterPageLoadKeepsNewOnlyWhenTicked(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new', 'new-project-cutoff' => '2026-10-02 00:04:51'];
        $this->call('seedDefaultsIfControlCenterModulePage', 'manager/control_center.php', null);
        $this->assertSame('new', $this->module->sys['apply-to']);
        $this->assertSame('2026-10-02 00:04:51', $this->module->sys['new-project-cutoff']);
    }

    public function testNewOnlyKeptWhileEnableOnAllTicked(): void {
        $this->module->sys = ['enabled' => true, 'apply-to' => 'new'];
        $this->module->redcap_module_save_configuration('');
        $this->assertSame('new', $this->module->sys['apply-to']);
    }

    /* ------------------------------------------------------------------
     * Control Center dialog order
     * ---------------------------------------------------------------- */

    public function testApplyToMovedDirectlyUnderBuiltInEnableSetting(): void {
        $settings = [
            ['key' => 'enabled'], ['key' => 'discoverable-in-project'], ['key' => 'user-activate-permission'],
            ['key' => 'header-appearance'], ['key' => 'apply-to'], ['key' => 'watermark-text'],
        ];
        $result = $this->module->redcap_module_configuration_settings(null, $settings);

        $this->assertSame(
            ['enabled', 'apply-to', 'discoverable-in-project', 'user-activate-permission', 'header-appearance', 'watermark-text'],
            array_column($result, 'key')
        );
    }

    /** @dataProvider overrideNoteCases */
    public function testOverridableNoteReplacedWithToggleableCopy(bool $allowed, string $display): void {
        $settings = [
            ['key' => 'enabled', 'name' => 'Enable'],
            ['key' => 'watermark-text', 'name' => '<b>Watermark text</b>', 'allow-project-overrides' => true],
            ['key' => 'ramp-enabled', 'name' => 'Ramp'],
        ];
        $this->module->sys = ['allow-project-override' => $allowed];
        $result = $this->module->redcap_module_configuration_settings(null, $settings);

        // REDCap's own note is suppressed (flag removed) and the module's toggleable copy added
        $this->assertArrayNotHasKey('allow-project-overrides', $result[1]);
        $this->assertStringContainsString('class="wm-override-note" style="display:' . $display . '"', $result[1]['name']);
        $this->assertStringContainsString('(this setting can be overridden on each project)', $result[1]['name']);
        $this->assertSame('Ramp', $result[2]['name'], 'non-overridable settings untouched');
    }

    public function overrideNoteCases(): array {
        return [
            'overrides on: note shown'  => [true, 'inline'],
            'overrides off: note hidden' => [false, 'none'],
        ];
    }

    public function testOverridableNoteUsesRedcapTranslation(): void {
        \ExternalModules\ExternalModules::$strings['em_manage_121'] = '(ce paramètre peut être modifié)';
        $settings = [['key' => 'watermark-opacity', 'name' => 'Opacity', 'allow-project-overrides' => true]];
        $result = $this->module->redcap_module_configuration_settings(null, $settings);
        $this->assertStringContainsString('(ce paramètre peut être modifié)', $result[0]['name']);
    }

    public function testProjectDialogHidesAppearanceWhenOverridesOff(): void {
        $settings = [
            ['key' => 'reserved-hide-from-non-admins-in-project-list'],
            ['key' => 'watermark-text'], ['key' => 'watermark-color-picker'], ['key' => 'watermark-color'],
            ['key' => 'watermark-opacity'], ['key' => 'watermark-font-size'],
        ];

        $this->module->sys = ['allow-project-override' => false];
        $result = $this->module->redcap_module_configuration_settings(5, $settings);
        $this->assertSame(
            ['reserved-hide-from-non-admins-in-project-list', 'appearance-locked-note'],
            array_column($result, 'key'),
            'REDCap options stay; appearance fields replaced by a note'
        );

        $this->module->sys = ['allow-project-override' => true];
        $this->assertSame(array_column($settings, 'key'),
            array_column($this->module->redcap_module_configuration_settings(5, $settings), 'key'),
            'all fields kept when overrides are allowed');
    }

    public function testProjectLabelsShowSiteLimits(): void {
        $this->site(['project-min-chars' => '5', 'project-min-font-size' => '48', 'project-min-opacity' => '0.15']);
        $settings = [
            ['key' => 'watermark-text', 'name' => 'x'], ['key' => 'watermark-opacity', 'name' => 'x'],
            ['key' => 'watermark-font-size', 'name' => 'x'], ['key' => 'watermark-color', 'name' => 'Colour code'],
        ];
        $names = array_column($this->module->redcap_module_configuration_settings(5, $settings), 'name');

        $this->assertStringContainsString('(at least 5 letters or digits)', $names[0]);
        $this->assertStringContainsString('(0.15 – 1.0)', $names[1]);
        $this->assertStringContainsString('(pt, 48 – 300)', $names[2]);
        $this->assertSame('Colour code', $names[3], 'other labels untouched');
    }

    public function testLiveValidationConfigCarriesLimitsAndSiteValues(): void {
        $this->site(['project-min-font-size' => '60', 'watermark-text' => 'SITE TEXT']);
        $cfg = $this->call('getLiveValidationConfig');
        $this->assertSame(60.0, $cfg['minFont']);
        $this->assertSame(4, $cfg['minChars']);
        $this->assertSame('SITE TEXT', $cfg['site']['watermark-text']);
        $this->assertSame(1.5, $cfg['minContrast']);
    }

    public function testLiveValidationScriptOnlyOnProjectModulePage(): void {
        $this->assertTrue($this->call('isProjectModulePage', 'manager/project.php', 5));
        $this->assertFalse($this->call('isProjectModulePage', 'manager/control_center.php', null));
        $this->assertFalse($this->call('isProjectModulePage', 'DataEntry/index.php', 5));

        $this->site(['watermark-text' => '</script><b>']);
        ob_start();
        $this->call('outputLiveValidationScript');
        $js = ob_get_clean();
        $this->assertStringContainsString('wm-live-error', $js);
        $this->assertSame(1, substr_count($js, '</script>'), 'site values are escaped inside the script');
    }

    public function testEveryPageHookOutputsNothingOnOtherPages(): void {
        // PAGE is "DataEntry/index.php" in the test bootstrap: not a page this hook handles
        $this->site(['enabled' => true, 'apply-to' => 'all']);

        ob_start();
        $this->module->redcap_every_page_top(null);   // system page
        $this->module->redcap_every_page_top(1);      // project page
        $this->assertSame('', ob_get_clean());
        $this->assertArrayNotHasKey('project-min-chars', array_diff_key($this->module->sys, ['project-min-chars' => 1]), 'no seeding off the Control Center page');
    }

    public function testColourSyncScriptTargetsOnlyThisModulesPicker(): void {
        ob_start();
        $this->call('outputColourSyncScript');
        $js = ob_get_clean();

        $this->assertStringContainsString('input[name="watermark-color"]', $js);
        $this->assertStringContainsString("p.spectrum('set'", $js, 'code box -> picker');
        $this->assertStringContainsString('input[name="watermark-color-picker"]', $js);
        $this->assertStringContainsString("toHexString()", $js, 'picker -> code box');
        $this->assertStringContainsString('if (syncing', $js, 'guard against updates bouncing back');
        $this->assertStringContainsString('"watermark_for_develpment_databases"', $js, 'checks the dialog belongs to this module');
    }

    public function testToggleScriptOnlyOnControlCenterModulePage(): void {
        $this->assertTrue($this->call('isControlCenterModulePage', 'manager/control_center.php', null));
        $this->assertFalse($this->call('isControlCenterModulePage', 'manager/project.php', 5));
        $this->assertFalse($this->call('isControlCenterModulePage', 'ControlCenter/index.php', null));

        ob_start();
        $this->call('outputOverrideNoteToggleScript');
        $js = ob_get_clean();
        $this->assertStringContainsString("box.name !== 'allow-project-override'", $js);
        $this->assertStringContainsString('.wm-override-note', $js);
    }

    public function testDialogOrderUnchangedInProjectsOrWithoutBuiltInSetting(): void {
        $settings = [['key' => 'watermark-text', 'name' => 'x'], ['key' => 'apply-to', 'name' => 'y']];
        $this->assertSame(['watermark-text', 'apply-to'], array_column($this->module->redcap_module_configuration_settings(5, $settings), 'key'));
        $this->assertSame(['watermark-text', 'apply-to'], array_column($this->module->redcap_module_configuration_settings(null, $settings), 'key'));
    }

    public function testProductionProjectGetsNoWatermark(): void {
        $GLOBALS['Proj']->project['status'] = 1;
        $this->assertFalse($this->call('shouldDisplayWatermark', 1));
    }

    /* ------------------------------------------------------------------
     * Seeding Control Center defaults
     * ---------------------------------------------------------------- */

    public function testSeedFillsMissingValues(): void {
        $this->module->redcap_module_system_change_version('v1.1.0', 'v1.0');
        $this->assertSame('TEST DATA ONLY', $this->module->sys['watermark-text']);
        $this->assertSame('#F26600', $this->module->sys['watermark-color-picker']);
        $this->assertSame('48', $this->module->sys['project-min-font-size']);
        $this->assertTrue($this->module->sys['allow-project-override']);
    }

    public function testControlCenterModulePageSeedsDefaults(): void {
        // Covers installs where the enable/upgrade hooks never seeded the values.
        // "manager/control_center.php" is how REDCap's System::defineAppConstants() names this page.
        $this->call('seedDefaultsIfControlCenterModulePage', 'manager/control_center.php', null);
        $this->assertSame('TEST DATA ONLY', $this->module->sys['watermark-text']);
        $this->assertTrue($this->module->sys['allow-project-override'], 'the checkbox must show ticked to match behaviour');
    }

    /** @dataProvider nonSeedingPageCases */
    public function testOtherPagesDoNotSeed(string $page, $projectId): void {
        $this->call('seedDefaultsIfControlCenterModulePage', $page, $projectId);
        $this->assertSame([], $this->module->sys);
    }

    public function nonSeedingPageCases(): array {
        return [
            'project module page'  => ['manager/project.php', 1],
            'data entry page'      => ['DataEntry/index.php', 1],
            'other Control Center' => ['ControlCenter/index.php', null],
        ];
    }

    public function testSeedNeverOverwritesAdminValues(): void {
        $this->module->sys = ['watermark-opacity' => '0.3', 'allow-project-override' => false];
        $this->module->redcap_module_system_change_version('v1.1.1', 'v1.1.0');
        $this->assertSame('0.3', $this->module->sys['watermark-opacity']);
        $this->assertFalse($this->module->sys['allow-project-override']);
    }

    /* ------------------------------------------------------------------
     * CSS output
     * ---------------------------------------------------------------- */

    public function testCssOutput(): void {
        $this->site(['watermark-text' => 'SAY "HI"']);
        ob_start();
        $this->module->displayWatermark(1);
        $css = ob_get_clean();

        $this->assertStringContainsString('font-size: 90pt;', $css);
        $this->assertStringContainsString('rgba(242, 102, 0, 0.1)', $css);
        $this->assertStringContainsString('content: "SAY \"HI\"";', $css, 'quotes are escaped for CSS');
        $this->assertStringContainsString('left: var(--wm-center-x, 40%);', $css);
    }

    public function testCssCentresOverContentAndFitsScreen(): void {
        $this->site(['watermark-text' => 'TEST DATA ONLY', 'watermark-font-size' => '90']);
        ob_start();
        $this->module->displayWatermark(1);
        $out = ob_get_clean();

        $this->assertStringContainsString('left: var(--wm-center-x, 40%);', $out, 'falls back to 40% without the script');
        $this->assertStringContainsString('top: min(50%, 450px);', $out, 'halfway down, but no lower than 450px');
        $this->assertStringContainsString('font-size: 90pt;', $out, 'fallback for browsers without min()');
        $this->assertStringContainsString('font-size: min(90pt, calc(var(--wm-avail, 100vmin) * 0.13));', $out, '182 / 14 characters = 13% of the space');
        $this->assertStringContainsString("'--wm-avail'", $out);
        $this->assertStringContainsString("getElementById('west')", $out, 'positioning script included');
    }

    public function testSurveyCentredWithoutPositionScript(): void {
        ob_start();
        $this->module->displayWatermark(1, true);
        $out = ob_get_clean();

        $this->assertStringContainsString('left: 50%;', $out);
        $this->assertStringContainsString('top: min(50%, 450px);', $out, 'surveys capped too');
        $this->assertStringNotContainsString('<script>', $out);
    }

    /** @dataProvider fitCases */
    public function testFitToScreenScalesWithTextLength(int $chars, float $vmin): void {
        $this->assertSame($vmin, $this->call('fitToScreenVmin', $chars));
    }

    public function fitCases(): array {
        return [
            'short text'   => [4, 45.5],
            'default text' => [14, 13.0],
            'long text'    => [40, 4.55],
            'zero guarded' => [0, 182.0],
        ];
    }

    public function testCssStripsTags(): void {
        $this->site(['watermark-text' => '</style><script>x</script>TEST']);
        ob_start();
        $this->module->displayWatermark(1, true);
        $css = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $css);
        $this->assertStringContainsString('left: 50%;', $css, 'surveys are centred');
    }
}
