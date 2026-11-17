<?php

namespace DrupalUpdater\Tests\Unit\Simulator;

use DrupalUpdater\Tests\Simulator\ComposerSimulator;
use DrupalUpdater\Tests\Simulator\Rule;
use DrupalUpdater\Tests\Simulator\Scenario;

/**
 * Covers rule matching, ordering and effects.
 */
final class RuleTest extends SimulatorTestBase {

  /**
   * Tests a rule matches when all its tokens are present, in any order.
   */
  public function testMatchesWhenAllTokensArePresent(): void {
    $rule = new Rule(['update', 'example/a']);

    self::assertTrue($rule->matches(['update', '--with-dependencies', 'example/a']));
    self::assertFalse($rule->matches(['update', 'example/b']));
    self::assertTrue((new Rule([]))->matches(['anything']));
  }

  /**
   * Tests the first matching rule answers, and a once rule only once.
   */
  public function testFirstMatchingRuleAnswersAndOnceRulesAreConsumed(): void {
    $scenario = new Scenario();
    $scenario->composer()->on('update')->once()->fails('first', 3);
    $scenario->composer()->on('update')->outputs('later');
    $scenario->composer()->on('update')->outputs('never');

    $first = $this->invocation($scenario, Scenario::COMPOSER, 'update');
    self::assertSame(3, (new ComposerSimulator())->handle($first));
    self::assertSame('first', $this->stderr());

    foreach ([1, 2] as $attempt) {
      $later = $this->nextInvocation($first->state(), Scenario::COMPOSER, 'update');
      self::assertSame(0, (new ComposerSimulator())->handle($later), 'Attempt ' . $attempt);
      self::assertSame('later', $this->stdout());
    }
  }

  /**
   * Tests effects run in order and a failure stops the rest.
   */
  public function testFailingEffectStopsTheRemainingOnes(): void {
    $rule = (new Rule(['update']))
      ->writes('before.txt', 'written')
      ->fails('boom', 2)
      ->writes('after.txt', 'never');

    $exit_code = $rule->apply($this->invocation(new Scenario(), Scenario::COMPOSER, 'update'));

    self::assertSame(2, $exit_code);
    self::assertFileExists($this->directory . '/before.txt');
    self::assertFileDoesNotExist($this->directory . '/after.txt');
    self::assertSame('boom', $this->stderr());
  }

  /**
   * Tests a rule survives the round trip through the scenario file.
   */
  public function testRuleSurvivesSerialization(): void {
    $rule = (new Rule(['update', 'example/a']))->once()->sleeps(0.01)->bumps('example/a')->passthrough();

    self::assertSame($rule->toArray(), Rule::fromArray($rule->toArray())->toArray());
  }

}
