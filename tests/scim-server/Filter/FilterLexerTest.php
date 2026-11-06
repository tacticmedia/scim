<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Filter\FilterLexer;
use TacticMedia\ScimServer\Filter\FilterTokenType;

final class FilterLexerTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, list<array{FilterTokenType, string, int}>}>
     */
    public static function inputs(): iterable
    {
        yield 'comparison' => ['userName eq "a b"', [
            [FilterTokenType::Name, 'userName', 0],
            [FilterTokenType::Name, 'eq', 9],
            [FilterTokenType::String, '"a b"', 12],
        ]];
        yield 'URN path and number' => ['urn:ietf:params:scim:schemas:core:2.0:User:loginCount gt -1.5E3', [
            [FilterTokenType::Name, 'urn:ietf:params:scim:schemas:core:2.0:User:loginCount', 0],
            [FilterTokenType::Name, 'gt', 54],
            [FilterTokenType::Number, '-1.5E3', 57],
        ]];
        yield 'groups without spaces' => ['not(emails[type eq"w"].value)', [
            [FilterTokenType::Name, 'not', 0],
            [FilterTokenType::OpenParenthesis, '(', 3],
            [FilterTokenType::Name, 'emails', 4],
            [FilterTokenType::OpenBracket, '[', 10],
            [FilterTokenType::Name, 'type', 11],
            [FilterTokenType::Name, 'eq', 16],
            [FilterTokenType::String, '"w"', 18],
            [FilterTokenType::CloseBracket, ']', 21],
            [FilterTokenType::SubAttribute, '.value', 22],
            [FilterTokenType::CloseParenthesis, ')', 28],
        ]];
        yield 'escaped quote' => ['"a\"b"', [[FilterTokenType::String, '"a\"b"', 0]]];
        yield 'unknown characters' => ["x eq 'y' #", [
            [FilterTokenType::Name, 'x', 0],
            [FilterTokenType::Name, 'eq', 2],
            [FilterTokenType::Unknown, "'y'", 5],
            [FilterTokenType::Unknown, '#', 9],
        ]];
        yield 'unterminated string' => ['"abc', [[FilterTokenType::Unknown, '"abc', 0]]];
        yield 'invalid UTF-8' => ["\xFF", [[FilterTokenType::Unknown, "\xFF", 0]]];
    }

    /**
     * @param list<array{FilterTokenType, string, int}> $expectedTokens
     */
    #[Test]
    #[DataProvider('inputs')]
    #[TestDox('Splits $input into typed tokens')]
    public function splitsInputIntoTypedTokens(string $input, array $expectedTokens): void
    {
        $lexer = new FilterLexer();
        $lexer->setInput($input);

        $tokens = [];

        while ($lexer->moveNext()) {
            $tokens[] = [$lexer->lookahead->type, $lexer->lookahead->value, $lexer->lookahead->position];
        }

        self::assertSame($expectedTokens, $tokens);
    }
}
