<?php declare(strict_types=1);
/**
 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
 *
 * Unauthorized copying of this file, via any medium, is
 * strictly prohibited without consent. Any dissemination of
 * material herein is prohibited.
 *
 * For licensing inquiries email <licensing@apisnetworks.com>
 *
 * Written by Matt Saladna <matt@apisnetworks.com>, July 2020
 */

namespace Module\Support\Webapps\App\Type\Wordpress;

use Module\Support\Php\TreeWalker;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;

/**
 * Class AST
 *
 * @package Module\Support\Webapps\App\Type\Wordpress
 *
 */
class DefineReplace extends TreeWalker
{
	/**
	 * Get value from AST
	 *
	 * @param string $var
	 * @param mixed $default
	 * @return mixed|null
	 */
	public function get(string $var, mixed $default = null): mixed
	{
		/** @var Node $found */
		$nodeFinder = new NodeFinder;
		$result = $nodeFinder->findFirst($this->ast, function (Node $node) use ($var) {
			if (!$node instanceof \PhpParser\Node\Expr\FuncCall || $node->name->toLowerString() !== 'define') {
				return false;
			}

			return $node->args[0]->value->value === $var;
		});

		if (!$result) {
			return $default;
		}

		$found = $result->args[1];

		try {
			return (new ConstExprEvaluator)->evaluateSilently($found->value);
		} catch (ConstExprEvaluationException $expr) {
			return (new \PhpParser\PrettyPrinter\Standard())->prettyPrint(
				[$found]
			);
		}
	}

	/**
	 * @{@inheritDoc}
	 */
	public function replace(string $var, mixed $new): self
	{
		return $this->walkReplace($var, $new, false);
	}

	/**
	 * @{@inheritDoc}
	 */
	public function set(string $var, mixed $new): self
	{
		return $this->walkReplace($var, $new, true);
	}

	/**
	 * Walk tree applying substitution rules
	 *
	 * @param string $var
	 * @param mixed $new
	 * @param bool   $append append if not found
	 * @return $this
	 */
	protected function walkReplace(string $var, mixed $new, bool $append = false): self
	{
		$normalizer = $this->inferType(...);
		$traverser = new NodeTraverser;

		$traverser->addVisitor(new class($var, $new, $append, $normalizer) extends \PhpParser\NodeVisitorAbstract {
			protected $duo;
			protected $count = 0;
			protected $append = false;
			protected \Closure $normalizer;

			public function __construct($var, $replacement, $append, $normalizer)
			{
				$this->duo = [$var, $replacement];
				$this->append = $append;
				$this->normalizer = $normalizer;
			}

			public function leaveNode(\PhpParser\Node $node)
			{
				if ($this->append && !$this->count && $node instanceof Stmt
					&& ($node->expr ?? null) instanceof Include_)
				{
					return array_merge([
						 new Stmt\Expression(new FuncCall(
							new Name('define'),
							[
								new String_($this->duo[0]),
								($this->normalizer)($this->duo[1])
							]
						))
					], [$node]);
				}

				if (!$node instanceof \PhpParser\Node\Expr\FuncCall || $node->name->toLowerString() !== 'define') {
					return;
				}

				if ($node->args[0]->value->value !== $this->duo[0]) {
					return;
				}
				$this->count++;
				$node->args[1] = ($this->normalizer)($this->duo[1]);
			}

		});

		$traverser->traverse($this->ast);
		return $this;
	}
}