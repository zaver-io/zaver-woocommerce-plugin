<?php
namespace Zaver;
use WC_Log_Levels;

final class Log {
	private $logger;
	private $context;

	static public function logger(): self {
		static $instance = null;

		if(is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	private function __construct() {
		$this->logger = wc_get_logger();
		$this->context = [
			'source' => 'zaver-checkout'
		];
	}

	private function log(string $level, string $message, array $args): void {
		$context = $this->context;

		if(!empty($args)) {
			$last = end($args);

			if(is_array($last)) {
				$context = array_merge($context, array_pop($args));
			}

			if(!empty($args)) {
				$message = vsprintf($message, $args);
			}
		}
		
		$this->logger->log($level, $message, $context);
	}

	public function debug(string $message, ...$args): void {
		$this->log(WC_Log_Levels::DEBUG, $message, $args);
	}

	public function info(string $message, ...$args): void {
		$this->log(WC_Log_Levels::INFO, $message, $args);
	}

	public function notice(string $message, ...$args): void {
		$this->log(WC_Log_Levels::NOTICE, $message, $args);
	}

	public function warning(string $message, ...$args): void {
		$this->log(WC_Log_Levels::WARNING, $message, $args);
	}

	public function error(string $message, ...$args): void {
		$this->log(WC_Log_Levels::ERROR, $message, $args);
	}

	public function critical(string $message, ...$args): void {
		$this->log(WC_Log_Levels::CRITICAL, $message, $args);
	}

	public function alert(string $message, ...$args): void {
		$this->log(WC_Log_Levels::ALERT, $message, $args);
	}

	public function emergency(string $message, ...$args): void {
		$this->log(WC_Log_Levels::EMERGENCY, $message, $args);
	}
}