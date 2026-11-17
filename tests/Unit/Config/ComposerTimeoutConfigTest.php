<?php

namespace DrupalUpdater\Tests\Unit\Config;

use DrupalUpdater\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests the composerTimeout configuration key.
 */
final class ComposerTimeoutConfigTest extends TestCase {

  /**
   * Tests the default timeout and a configured one.
   */
  public function testDefaultAndConfiguredTimeout(): void {
    self::assertSame(300, (new Config())->getComposerTimeout());
    self::assertSame(300, $this->configuration('')->getComposerTimeout());
    self::assertSame(1800, $this->configuration('composerTimeout: 1800')->getComposerTimeout());
  }

  /**
   * Tests invalid timeouts are rejected.
   */
  public function testInvalidTimeoutsAreRejected(): void {
    foreach (['0', '-1', 'null', '"1800"', 'true', '1.5'] as $value) {
      try {
        $this->configuration('composerTimeout: ' . $value);
        self::fail('Accepted invalid timeout: ' . $value);
      }
      catch (\InvalidArgumentException $e) {
        self::assertStringContainsString('composerTimeout', $e->getMessage());
      }
    }
  }

  /**
   * Loads a configuration from YAML contents.
   */
  private function configuration(string $yaml): Config {
    $file = tempnam(sys_get_temp_dir(), 'updater-timeout-');
    try {
      file_put_contents($file, $yaml);
      return Config::createFromConfigurationFile($file);
    }
    finally {
      unlink($file);
    }
  }

}
