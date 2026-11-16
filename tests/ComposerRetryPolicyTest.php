<?php

namespace DrupalUpdater\Tests;

use DrupalUpdater\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Composer retry configuration and policy.
 */
class ComposerRetryPolicyTest extends TestCase {

  /**
   * Tests the default retry policy and disabling retries.
   */
  public function testDefaultPolicyAndDisableSetting(): void {
    self::assertSame(3, (new Config())->getComposerAttempts());
    self::assertSame(10, (new Config())->getComposerRetryDelay());
    self::assertSame(1, (new Config())->getComposerAdditionalPasses());
    $config = $this->configuration("composerAttempts: 1\ncomposerRetryDelay: 0\n");
    self::assertSame(1, $config->getComposerAttempts());
    self::assertSame(0, $config->getComposerRetryDelay());
  }

  /**
   * Tests out of bounds and non-integer values are rejected.
   */
  public function testInvalidBoundsAndTypesAreRejected(): void {
    $invalid = [
      'composerAttempts: 0',
      'composerAttempts: 11',
      'composerAttempts: "3"',
      'composerAttempts: null',
      'composerRetryDelay: -1',
      'composerRetryDelay: 301',
      'composerRetryDelay: true',
      'composerAdditionalPasses: -1',
      'composerAdditionalPasses: 4',
      'composerAdditionalPasses: "1"',
      'composerAdditionalPasses: null',
    ];
    foreach ($invalid as $yaml) {
      try {
        $this->configuration($yaml);
        self::fail('Accepted ' . $yaml);
      }
      catch (\InvalidArgumentException $e) {
        self::assertStringContainsString('must be an integer', $e->getMessage());
      }
    }
  }

  /**
   * Tests the backoff doubles on each attempt up to five minutes.
   */
  public function testBackoffDoublesAndCapsAtFiveMinutes(): void {
    $command = new RetryPolicyCommand(new Config());
    self::assertSame(10, $command->delay(1));
    self::assertSame(20, $command->delay(2));
    self::assertSame(160, $command->delay(5));
    self::assertSame(300, $command->delay(6));
    self::assertSame(300, $command->delay(10));
  }

  /**
   * Tests only clearly transient failures are retried.
   */
  public function testClassificationIsConservative(): void {
    $command = new RetryPolicyCommand(new Config());
    foreach (['curl error 28: connection timed out', 'HTTP/2 503 unavailable', 'could not resolve host'] as $message) {
      self::assertTrue($command->transient($message), $message);
    }
    $permanent = [
      'Your requirements could not be resolved',
      'HTTP/2 403 curl error 28',
      'authentication failed',
      'Permission denied',
      'Unknown failure',
    ];
    foreach ($permanent as $message) {
      self::assertFalse($command->transient($message), $message);
    }
  }

  /**
   * Loads a configuration from YAML contents.
   */
  private function configuration(string $yaml): Config {
    $path = tempnam(sys_get_temp_dir(), 'retry-config-');
    try {
      file_put_contents($path, $yaml);
      return Config::createFromConfigurationFile($path);
    }
    finally {
      unlink($path);
    }
  }

}
