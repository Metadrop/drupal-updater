<?php

namespace DrupalUpdater\Tests;

use DrupalUpdater\Config\Config;
use DrupalUpdater\UpdaterCommand;
use PHPUnit\Framework\TestCase;

class RetryPolicyCommand extends UpdaterCommand {
  public function __construct(Config $config) { parent::__construct(); $this->setConfiguration($config); }
  public function delay(int $attempt): int { return $this->composerRetryDelay($attempt); }
  public function transient(string $message): bool { return $this->isTransientComposerFailure(new \RuntimeException($message)); }
}

class ComposerRetryPolicyTest extends TestCase {
  public function testDefaultPolicyAndDisableSetting(): void {
    self::assertSame(3, (new Config())->getComposerAttempts());
    self::assertSame(10, (new Config())->getComposerRetryDelay());
    self::assertSame(1, (new Config())->getComposerAdditionalPasses());
    $config = $this->configuration("composerAttempts: 1\ncomposerRetryDelay: 0\n");
    self::assertSame(1, $config->getComposerAttempts());
    self::assertSame(0, $config->getComposerRetryDelay());
  }

  public function testInvalidBoundsAndTypesAreRejected(): void {
    foreach (['composerAttempts: 0', 'composerAttempts: 11', 'composerAttempts: "3"', 'composerAttempts: null', 'composerRetryDelay: -1', 'composerRetryDelay: 301', 'composerRetryDelay: true', 'composerAdditionalPasses: -1', 'composerAdditionalPasses: 4', 'composerAdditionalPasses: "1"', 'composerAdditionalPasses: null'] as $yaml) {
      try { $this->configuration($yaml); self::fail('Accepted ' . $yaml); }
      catch (\InvalidArgumentException $e) { self::assertStringContainsString('must be an integer', $e->getMessage()); }
    }
  }

  public function testBackoffDoublesAndCapsAtFiveMinutes(): void {
    $command = new RetryPolicyCommand(new Config());
    self::assertSame(10, $command->delay(1));
    self::assertSame(20, $command->delay(2));
    self::assertSame(160, $command->delay(5));
    self::assertSame(300, $command->delay(6));
    self::assertSame(300, $command->delay(10));
  }

  public function testClassificationIsConservative(): void {
    $command = new RetryPolicyCommand(new Config());
    foreach (['curl error 28: connection timed out', 'HTTP/2 503 unavailable', 'could not resolve host'] as $message) {
      self::assertTrue($command->transient($message), $message);
    }
    foreach (['Your requirements could not be resolved', 'HTTP/2 403 curl error 28', 'authentication failed', 'Permission denied', 'Unknown failure'] as $message) {
      self::assertFalse($command->transient($message), $message);
    }
  }

  private function configuration(string $yaml): Config {
    $path = tempnam(sys_get_temp_dir(), 'retry-config-');
    try { file_put_contents($path, $yaml); return Config::createFromConfigurationFile($path); }
    finally { unlink($path); }
  }
}
