<?php namespace ProcessWire;

/**
 * AtCronLog
 *
 * Mirrors the AgentTools cron log file (`site/assets/logs/at-cron.log`) into a
 * ProcessWire log, so its content is visible under Setup > Logs next to all other
 * ProcessWire logs.
 *
 * Why this exists: the `--at-cron` job is started by a system cron entry with
 * `>> site/assets/logs/at-cron.log 2>&1`. On some hosts (e.g. Hostinger shared
 * hosting) that shell redirect creates the file but never receives the output, so
 * nothing can be read there. This module therefore
 *
 * 1. mirrors any lines that do land in the file (incremental, byte-offset based),
 * 2. records what every AgentTools cron run did (`processed`, `failed`, `pending`),
 *    derived from the job queue directories, so the log is never uselessly empty.
 *
 * ProcessWire 3.x, PHP 8.2+
 *
 * @author webmanufaktur
 * @version 2
 *
 */
class AtCronLog extends WireData implements Module, ConfigurableModule {

	/**
	 * AgentTools job queue statuses, in the order they are reported.
	 */
	const jobStatuses = [ 'pending', 'running', 'done', 'failed' ];

	/**
	 * ProcessWire's log viewer page.
	 */
	const hookLogsView = 'ProcessLogger::execute';

	/**
	 * Guards against ingesting twice within one request.
	 *
	 * @var bool
	 *
	 */
	protected $ingested = false;

	/**
	 * Guards against evaluating the cron run twice within one request.
	 *
	 * ProcessWire::finished is not guaranteed to fire only once (AgentTools and other
	 * modules may trigger another finish), so the shutdown hook is made idempotent.
	 *
	 * @var bool
	 *
	 */
	protected $cronFinishedHandled = false;

	/**
	 * Construct
	 *
	 */
	public function __construct() {
		parent::__construct();
		$this->setArray(self::getDefaultConfig());
	}

	/**
	 * Module information
	 *
	 * @return array
	 *
	 */
	public static function getModuleInfo() {
		return [
			'title' => 'AgentTools Cron Log → PW Logs',
			'version' => 2,
			'summary' => __('Mirrors a cron log file (default: site/assets/logs/at-cron.log) into a ProcessWire log, visible under Setup > Logs, and records what each AgentTools cron run did.'),
			'autoload' => true,
			'singular' => true,
			'requires' => [ 'ProcessWire>=3.0.200', 'PHP>=8.0' ],
			'icon' => 'file-text',
			'author' => 'webmanufaktur',
			'license' => 'MIT',
			'href' => 'https://github.com/webmanufaktur/AtCronLog',
		];
	}

	/**
	 * Default configuration
	 *
	 * @return array
	 *
	 */
	public static function getDefaultConfig() {
		return [
			// Log file read by this module, relative to the ProcessWire root
			'logFile' => 'site/assets/logs/at-cron.log',
			// Name of the ProcessWire log the lines are written to
			'logName' => 'at-cron',
			// Record the outcome of every cron run that did actual work (or failed)
			'captureRuns' => 1,
			// Also record idle runs, but at most once per this many minutes (0 = never)
			'captureInterval' => 60,
			// Ingest pending lines when Setup > Logs is opened
			'ingestOnLogsView' => 1,
			// Ingest pending lines once per minute via LazyCron (if installed)
			'ingestOnLazyCron' => 0,
			// Maximum bytes read from the log file per ingest
			'maxBytes' => 65536,
		];
	}

	/**
	 * Init: register hooks
	 *
	 */
	public function init() {
		$modules = $this->wire()->modules;

		if($modules->isInstalled('AgentTools') && PHP_SAPI === 'cli') {
			// AgentTools' runCron() is not a hookable method, so the cron run is
			// recognised from the CLI arguments and evaluated at shutdown instead.
			$argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
			if(in_array('--at-cron', $argv, true)) {
				$this->addHookAfter('ProcessWire::finished', $this, 'hookCronFinished');
			}
		}

		if($modules->isInstalled('ProcessLogger')) {
			$this->addHookBefore(self::hookLogsView, $this, 'hookLogsView');
		}

		if($modules->isInstalled('LazyCron') && $this->config('ingestOnLazyCron')) {
			$this->addHookAfter('LazyCron::everyMinute', $this, 'hookLazyCron');
		}
	}

	/**
	 * Get a configuration value
	 *
	 * Reads stored module config first (Setup > Modules > AtCronLog), falls back to
	 * the defaults from getDefaultConfig(). Values are always available, whether or
	 * not the installation is complete yet.
	 *
	 * @param string $key
	 * @param mixed|null $fallback Fallback when key is neither configured nor default
	 * @return mixed
	 *
	 */
	public function config(string $key, $fallback = null) {
		$config = $this->wire()->modules->getConfig($this);
		if(is_array($config) && array_key_exists($key, $config)) return $config[$key];
		if(array_key_exists($key, self::getDefaultConfig())) return self::getDefaultConfig()[$key];
		return $fallback;
	}

	/**
	 * Hook: after an AgentTools cron run (CLI, at shutdown)
	 *
	 * AgentTools reports each run on stdout only, and some hosts discard that. This
	 * hook therefore derives what the run did from the job queue directories and
	 * records it in the ProcessWire log — but only when the log file itself did not
	 * deliver any new lines, so nothing is recorded twice.
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookCronFinished(HookEvent $event) {
		if($this->cronFinishedHandled) return;
		$this->cronFinishedHandled = true;

		$state = $this->getState();
		$counters = $this->getJobCounters();
		$previous = isset($state['counters']) && is_array($state['counters']) ? $state['counters'] : null;

		$ingested = $this->ingest();

		if($ingested === 0 && $this->config('captureRuns') && $counters !== []) {
			// Jobs that left pending/running since the last run count as processed
			$processed = 0;
			$failed = 0;
			if($previous !== null) {
				$processed = max(0, (int) $counters['done'] - (int) ($previous['done'] ?? 0));
				$failed = max(0, (int) $counters['failed'] - (int) ($previous['failed'] ?? 0));
			}
			$interval = (int) $this->config('captureInterval');
			$lastEntry = (int) ($state['lastEntry'] ?? 0);
			$idleDue = $interval > 0 && (time() - $lastEntry) >= $interval * 60;
			if($processed + $failed > 0 || $idleDue) {
				$this->saveEntry(sprintf(
					'cron run: processed=%d failed=%d pending=%d',
					$processed, $failed, (int) $counters['pending']
				));
				$state['lastEntry'] = time();
			}
		}

		if($counters !== []) {
			$state['counters'] = $counters;
			$state['updated'] = time();
			$this->saveState($state);
		}
	}

	/**
	 * Count the job files in the AgentTools queue directories
	 *
	 * @return array Status => count, or empty array if AgentTools is not available
	 *
	 */
	public function getJobCounters(): array {
		$at = $this->wire()->modules->get('AgentTools');
		if(!$at || !method_exists($at, 'getFilesPath')) return [];
		$base = $at->getFilesPath('jobs');
		if(!is_string($base) || $base === '') return [];
		$counters = [];
		foreach(self::jobStatuses as $status) {
			$dir = rtrim($base, '/') . "/$status/";
			$counters[$status] = is_dir($dir) ? count((array) glob($dir . '*.json')) : 0;
		}
		return $counters;
	}

	/**
	 * Hook: before ProcessWire's log viewer renders
	 *
	 * Makes sure lines that arrived since the last cron run are visible.
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookLogsView(HookEvent $event) {
		if(!$this->config('ingestOnLogsView')) return;
		$this->ingest();
	}

	/**
	 * Hook: every minute via LazyCron
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookLazyCron(HookEvent $event) {
		$this->ingest();
	}

	/**
	 * Ingest new lines from the log file into the ProcessWire log
	 *
	 * Only complete lines are consumed; a trailing partial line stays unread until
	 * it is finished. If the file shrank (rotation/truncation) reading restarts at
	 * the beginning. Ingested lines are remembered by byte offset in
	 * site/assets/AtCronLog/state.json, so repeated calls do not duplicate entries.
	 *
	 * @return int Number of log entries written
	 *
	 */
	public function ingest(): int {
		if($this->ingested) return 0;
		$this->ingested = true;

		$file = $this->getLogFilePath();
		$logName = $this->getLogName();

		if($file === '' || $logName === '') return 0;
		if(!is_file($file) || !is_readable($file)) return 0;

		$size = filesize($file);
		if($size === false || $size < 1) return 0;

		$state = $this->getState();
		$offset = (int) ($state['offset'] ?? 0);
		if($offset > $size) $offset = 0; // file was truncated or rotated
		if($offset === $size) return 0;

		$maxBytes = (int) $this->config('maxBytes');
		if($maxBytes < 1024) $maxBytes = 1024;

		$data = $this->readBytes($file, $offset, $maxBytes);
		if($data === '') return 0;

		// Only consume complete lines, keep a trailing partial line for later
		$lastNewline = strrpos($data, "\n");
		if($lastNewline === false) return 0;
		$complete = substr($data, 0, $lastNewline + 1);

		$count = 0;
		foreach(preg_split('/\r\n|\n|\r/', $complete) as $line) {
			$line = trim($line);
			if($line === '') continue;
			$this->saveEntry($line, $logName);
			$count++;
		}

		$state['file'] = $file;
		$state['offset'] = $offset + strlen($complete);
		$state['updated'] = time();
		$this->saveState($state);

		return $count;
	}

	/**
	 * Write one entry to the ProcessWire log
	 *
	 * @param string $text
	 * @param string|null $logName
	 * @return bool
	 *
	 */
	protected function saveEntry(string $text, ?string $logName = null): bool {
		if($logName === null) $logName = $this->getLogName();
		if($logName === '' || $text === '') return false;
		return $this->wire()->log->save($logName, $text, [
			'showUser' => false,
			'showURL' => false,
			'allowNewlines' => false,
		]);
	}

	/**
	 * Read up to $maxBytes from $offset
	 *
	 * @param string $file
	 * @param int $offset
	 * @param int $maxBytes
	 * @return string
	 *
	 */
	protected function readBytes(string $file, int $offset, int $maxBytes): string {
		$fh = fopen($file, 'rb');
		if(!$fh) return '';
		$data = '';
		if(fseek($fh, $offset) === 0) {
			$data = (string) fread($fh, $maxBytes);
		}
		fclose($fh);
		return $data;
	}

	/**
	 * Get the absolute path of the log file to read
	 *
	 * The configured path is relative to the ProcessWire root. Paths that resolve
	 * outside the installation are rejected.
	 *
	 * @return string Empty string if the configured path is not usable
	 *
	 */
	public function getLogFilePath(): string {
		$config = $this->wire()->config;
		$root = rtrim($config->paths->root, '/') . '/';
		$file = (string) $this->config('logFile');
		if($file === '') return '';
		if(!str_starts_with($file, '/')) $file = $root . ltrim($file, '/');

		// Resolve the directory (the file itself may not exist yet)
		$dir = realpath(dirname($file));
		$rootReal = realpath($root);
		if($dir === false || $rootReal === false) return '';
		if(!str_starts_with($dir . '/', rtrim($rootReal, '/') . '/')) return '';

		return $dir . '/' . basename($file);
	}

	/**
	 * Get the sanitized name of the ProcessWire log
	 *
	 * @return string
	 *
	 */
	public function getLogName(): string {
		$name = $this->wire()->sanitizer->name((string) $this->config('logName'));
		return is_string($name) && $name !== '' ? $name : 'at-cron';
	}

	/**
	 * Get the state file path
	 *
	 * @param bool $create Create the directory if it does not exist?
	 * @return string
	 *
	 */
	protected function getStateFile(bool $create = false): string {
		$path = $this->wire()->config->paths->assets . 'AtCronLog/';
		if($create && !is_dir($path)) {
			$this->wire()->files->mkdir($path);
			$this->wire()->files->filePutContents($path . '.htaccess', "Deny from all\n");
		}
		return $path . 'state.json';
	}

	/**
	 * Load the ingest state
	 *
	 * @return array
	 *
	 */
	protected function getState(): array {
		$file = $this->getStateFile();
		if(!is_file($file)) return [];
		$json = @file_get_contents($file);
		if(!is_string($json) || $json === '') return [];
		$data = json_decode($json, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Save the ingest state
	 *
	 * @param array $state
	 *
	 */
	protected function saveState(array $state) {
		$this->getStateFile(true);
		$this->wire()->files->filePutContents(
			$this->getStateFile(),
			json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
		);
	}

	/**
	 * Module configuration fields
	 *
	 * @param InputfieldWrapper $inputfields
	 *
	 */
	public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
		$defaults = self::getDefaultConfig();

		$field = $inputfields->InputfieldText;
		$field->attr('name', 'logFile');
		$field->label = $this->_('Log file to mirror');
		$field->description = $this->_('Path relative to the ProcessWire root. Default: site/assets/logs/at-cron.log');
		$field->attr('value', (string) $this->config('logFile'));
		$field->required = true;
		$inputfields->add($field);

		$field = $inputfields->InputfieldText;
		$field->attr('name', 'logName');
		$field->label = $this->_('ProcessWire log name');
		$field->description = $this->_('Entries appear under Setup > Logs. The file is site/assets/logs/[name].txt.');
		$field->attr('value', (string) $this->config('logName'));
		$field->required = true;
		$inputfields->add($field);

		$field = $inputfields->InputfieldInteger;
		$field->attr('name', 'maxBytes');
		$field->label = $this->_('Maximum bytes per ingest');
		$field->description = $this->_('Only this much new content is read per call. Default: 65536');
		$field->attr('value', (int) $this->config('maxBytes'));
		$inputfields->add($field);

		$field = $inputfields->InputfieldInteger;
		$field->attr('name', 'captureInterval');
		$field->label = $this->_('Idle heartbeat interval (minutes)');
		$field->description = $this->_('Also record cron runs that did nothing, but at most once per this many minutes. 0 = never. Default: 60');
		$field->attr('value', (int) $this->config('captureInterval'));
		$inputfields->add($field);

		$checkboxes = [
			'captureRuns' => [
				$this->_('Record what cron runs did'),
				$this->_('Writes one line per AgentTools cron run that processed or failed a job (derived from the job queue), but only when the log file itself delivered no new lines.'),
			],
			'ingestOnLogsView' => [
				$this->_('Ingest when Setup > Logs is opened'),
				$this->_('Picks up lines written since the last cron run before the log is rendered.'),
			],
			'ingestOnLazyCron' => [
				$this->_('Ingest every minute via LazyCron'),
				$this->_('Requires the LazyCron core module. Not needed if AgentTools runs the cron.'),
			],
		];

		foreach($checkboxes as $name => $info) {
			$field = $inputfields->InputfieldCheckbox;
			$field->attr('name', $name);
			$field->label = $info[0];
			$field->description = $info[1];
			$field->attr('checked', $this->config($name) ? 'checked' : '');
			$inputfields->add($field);
		}

		$field = $inputfields->InputfieldMarkup;
		$field->label = $this->_('Log file');
		$file = $this->getLogFilePath();
		$exists = $file !== '' && is_file($file);
		$field->value =
			'<p><code>' . $this->wire()->sanitizer->entities($file ?: '—') . '</code><br>' .
			($exists
				? sprintf($this->_('Exists, %d bytes, last change %s'), filesize($file), date('Y-m-d H:i:s', filemtime($file)))
				: $this->_('Does not exist yet.')) . '</p>' .
			'<p class="detail">' . $this->_('An empty log file is normal on hosts where the cron wrapper discards stdout — the cron run results are then recorded by this module directly.') . '</p>';
		$inputfields->add($field);
	}

	/**
	 * Install
	 *
	 */
	public function install() {
		$this->getStateFile(true);
	}

	/**
	 * Uninstall
	 *
	 */
	public function uninstall() {
		$this->wire()->files->rmdir($this->wire()->config->paths->assets . 'AtCronLog/', true);
	}
}
