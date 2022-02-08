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

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;

/**
 * Class AST
 *
 * @package Module\Support\Webapps\App\Type\Wordpress
 *
 */
class DefineReplace {
	use \apnscpFunctionInterceptorTrait;
	use \ContextableTrait;

	/**
	 * @var \PhpParser\Node\Stmt[]
	 */
	protected $ast;
	/**
	 * @var \PhpParser\NodeTraverser
	 */
	protected $traverser;

	/** @var string filename */
	protected $file;
	/**
	 * Util_AST constructor.
	 *
	 * @param string $file
	 * @throws \ArgumentError
	 * @throws \PhpParser\Error
	 */
	protected function __construct(string $file)
	{
		if (!$this->file_exists($file)) {
			throw new \ArgumentError(\ArgumentFormatter::format("Target file %s does not exist", [$file]));
		}
		$code = $this->file_get_file_contents($this->file = $file);
		$parser = (new \PhpParser\ParserFactory)->create(\PhpParser\ParserFactory::PREFER_PHP7);
		$this->ast = $parser->parse($code);
		$this->traverser = new \PhpParser\NodeTraverser();
	}

	/**
	 * Replace matching define() rules
	 *
	 * @param string $var search variable
	 * @param mixed  $new replacement value
	 * @return self
	 */
	public function replace(string $var, $new): self
	{
		return $this->walkReplace($var, $new, false);
	}

	/**
	 * Set matching define() statements or add
	 *
	 * @param string $var search variable
	 * @param mixed  $new replacement value
	 * @return self
	 */
	public function set(string $var, $new): self {
		return $this->walkReplace($var, $new, true);
	}

	/**
	 * Get value from AST
	 *
	 * @param string $var
	 * @param        $default
	 * @return mixed|null
	 */
	public function get(string $var, $default = null) {
		/** @var Node $found */
		$found = null;
		$walker = $this->traverser->addVisitor(new class ($var, $found) extends \PhpParser\NodeVisitorAbstract
		{
			private string $var;
			private ?Node\Arg $found;

			public function __construct(string $var, &$found) {
				$this->found = &$found;
				$this->var = $var;
			}

			public function leaveNode(\PhpParser\Node $node)
			{
				if (!$node instanceof \PhpParser\Node\Expr\FuncCall || $node->name->toLowerString() !== 'define') {
					return;
				}
				if ($node->args[0]->value->value !== $this->var) {
					return;
				}
				$this->found = $node->args[1];
				return NodeTraverser::STOP_TRAVERSAL;
			}

		});

		$this->traverser->traverse($this->ast);

		if (null === $found) {
			return $default;
		}

		try {
			return (new ConstExprEvaluator)->evaluateSilently($found->value);
		} catch (ConstExprEvaluationException $expr) {
			return (new \PhpParser\PrettyPrinter\Standard())->prettyPrint(
				[$found]
			);
		}
	}

	/**
	 * Walk tree applying substitution rules
	 *
	 * @param string $var
	 * @param        $new
	 * @param bool   $append append if not found
	 * @return $this
	 */
	private function walkReplace(string $var, $new, bool $append = false): self
	{
		$this->traverser->addVisitor(new class($var, $new, $append) extends \PhpParser\NodeVisitorAbstract {
			protected $duo;
			protected $count = 0;
			protected $append = false;

			public function __construct($var, $replacement, $append)
			{
				$this->duo = [$var, $replacement];
				$this->append = $append;
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
								$this->inferType($this->duo[1])
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
				$node->args[1] = $this->inferType($this->duo[1]);
			}

			private function inferType($type): \PhpParser\NodeAbstract
			{
				return \PhpParser\BuilderHelpers::normalizeValue($this->duo[1]);
			}

		});

		return $this;
	}

	/**
	 * Generate configuration
	 *
	 * @return string
	 */
	public function __toString()
	{
		return (new \PhpParser\PrettyPrinter\Standard())->prettyPrint(
			$this->traverser->traverse($this->ast)
		);
	}

	/**
	 * Save configuration
	 *
	 * @return bool
	 */
	public function save(): bool {
		return $this->file_put_file_contents($this->file, '<?php' . "\n" . (string)$this);
	}


}