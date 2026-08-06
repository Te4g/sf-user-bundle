<?php

declare(strict_types=1);

namespace Te4g\Starter\Generation;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/** Parses the TwigComponent props declaration without requiring its runtime. */
final class SyntaxOnlyPropsTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();

        while (!$stream->nextIf(Token::BLOCK_END_TYPE)) {
            $stream->expect(Token::NAME_TYPE);

            if ($stream->nextIf(Token::OPERATOR_TYPE, '=')) {
                $this->parser->parseExpression();
            }

            if (!$stream->nextIf(Token::PUNCTUATION_TYPE, ',')) {
                $stream->expect(Token::BLOCK_END_TYPE);

                break;
            }
        }

        return new Node([], [], $token->getLine());
    }

    public function getTag(): string
    {
        return 'props';
    }
}
