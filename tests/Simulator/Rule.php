<?php

namespace DrupalUpdater\Tests\Simulator;

use DrupalUpdater\Tests\Simulator\Effect\EffectInterface;
use DrupalUpdater\Tests\Simulator\Effect\EffectFactory;
use DrupalUpdater\Tests\Simulator\Effect\Fail;
use DrupalUpdater\Tests\Simulator\Effect\Output;
use DrupalUpdater\Tests\Simulator\Effect\Passthrough;
use DrupalUpdater\Tests\Simulator\Effect\RemovePath;
use DrupalUpdater\Tests\Simulator\Effect\SetLockVersions;
use DrupalUpdater\Tests\Simulator\Effect\Sleep;
use DrupalUpdater\Tests\Simulator\Effect\WriteFile;

/**
 * A scripted answer to the commands that contain some arguments.
 *
 * A rule matches when every one of its tokens is among the command arguments,
 * whatever their order. Its effects run in order and the first non-zero exit
 * code ends the command; a rule without effects just succeeds.
 */
final class Rule {

  /**
   * Effects run when the rule matches.
   *
   * @var list<\DrupalUpdater\Tests\Simulator\Effect\EffectInterface>
   */
  private array $effects = [];

  /**
   * Whether the rule only answers the first matching command.
   */
  private bool $once = FALSE;

  /**
   * Creates a rule.
   *
   * @param list<string> $tokens
   *   Arguments the command must contain.
   */
  public function __construct(
    private array $tokens,
  ) {}

  /**
   * Restores a rule from its array form.
   *
   * @param array<string, mixed> $data
   *   Data produced by toArray().
   */
  public static function fromArray(array $data): self {
    $rule = new self($data['tokens']);
    $rule->once = $data['once'];
    $rule->effects = array_values(array_map([EffectFactory::class, 'fromArray'], $data['effects']));
    return $rule;
  }

  /**
   * Whether the rule answers the given command arguments.
   *
   * @param list<string> $arguments
   *   Command arguments, without the tool name.
   */
  public function matches(array $arguments): bool {
    return array_diff($this->tokens, $arguments) === [];
  }

  /**
   * Whether the rule only answers the first matching command.
   */
  public function isOnce(): bool {
    return $this->once;
  }

  /**
   * Runs the effects of the rule.
   */
  public function apply(Invocation $invocation): int {
    foreach ($this->effects as $effect) {
      $exit_code = $effect->apply($invocation);
      if ($exit_code !== 0) {
        return $exit_code;
      }
    }
    return 0;
  }

  /**
   * Answers only the first matching command; later ones go to the next rule.
   */
  public function once(): self {
    $this->once = TRUE;
    return $this;
  }

  /**
   * Succeeds without doing anything, e.g. an update that changes nothing.
   */
  public function succeeds(): self {
    return $this;
  }

  /**
   * Prints to the standard output and, optionally, to the error output.
   */
  public function outputs(string $stdout, string $stderr = ''): self {
    return $this->then(new Output($stdout, $stderr));
  }

  /**
   * Prints an error and exits with the given code.
   */
  public function fails(string $stderr = '', int $exit_code = 1): self {
    return $this->then(new Fail($stderr, $exit_code));
  }

  /**
   * Waits the given seconds.
   */
  public function sleeps(float $seconds): self {
    return $this->then(new Sleep($seconds));
  }

  /**
   * Writes a project file, relative to the project root.
   */
  public function writes(string $path, string $contents): self {
    return $this->then(new WriteFile($path, $contents));
  }

  /**
   * Removes a project file or directory, relative to the project root.
   */
  public function removes(string $path): self {
    return $this->then(new RemovePath($path));
  }

  /**
   * Locks the given packages at their latest version.
   */
  public function bumps(string ...$names): self {
    return $this->then(new SetLockVersions(array_fill_keys($names, NULL)));
  }

  /**
   * Locks a package at the given version.
   */
  public function locks(string $name, string $version): self {
    return $this->then(new SetLockVersions([$name => $version]));
  }

  /**
   * Runs the real tool with the same arguments.
   */
  public function passthrough(): self {
    return $this->then(new Passthrough());
  }

  /**
   * Adds an effect.
   */
  public function then(EffectInterface $effect): self {
    $this->effects[] = $effect;
    return $this;
  }

  /**
   * Gets the array form, stored in the scenario file.
   *
   * @return array<string, mixed>
   *   Rule data.
   */
  public function toArray(): array {
    return [
      'tokens' => $this->tokens,
      'once' => $this->once,
      'effects' => array_map(fn (EffectInterface $effect) => $effect->toArray(), $this->effects),
    ];
  }

}
