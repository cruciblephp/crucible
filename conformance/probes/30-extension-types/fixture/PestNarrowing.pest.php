<?php

declare(strict_types=1);

namespace CrucibleProbe\Types;

use function PHPStan\dumpType;

/*
 * What the Pest-dialect expectation chain tells the analyser: the type of
 * expect()'s value, the chain's value after a matcher, and the subject
 * variable after the statement. Labels as in PhpUnitNarrowingTest.
 */

test('the value expect() carries', function (): void {
    dumpType(expect(maybeString())->value); // pest.expect.value
    dumpType(expect(payload())->value); // pest.expect.array
});

test('toBeString', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeString();
    dumpType($e->value); // pest.toBeString.chain
    dumpType($v); // pest.toBeString.subject
});

test('toBeInt', function (): void {
    $v = intOrString();
    $e = expect($v)->toBeInt();
    dumpType($e->value); // pest.toBeInt.chain
    dumpType($v); // pest.toBeInt.subject
});

test('toBeFloat', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeFloat();
    dumpType($e->value); // pest.toBeFloat.chain
    dumpType($v); // pest.toBeFloat.subject
});

test('toBeBool', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeBool();
    dumpType($e->value); // pest.toBeBool.chain
    dumpType($v); // pest.toBeBool.subject
});

test('toBeArray', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeArray();
    dumpType($e->value); // pest.toBeArray.chain
    dumpType($v); // pest.toBeArray.subject
});

test('toBeList', function (): void {
    $v = payload();
    $e = expect($v)->toBeList();
    dumpType($e->value); // pest.toBeList.chain
    dumpType($v); // pest.toBeList.subject
});

test('toBeObject', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeObject();
    dumpType($e->value); // pest.toBeObject.chain
    dumpType($v); // pest.toBeObject.subject
});

test('toBeCallable', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeCallable();
    dumpType($e->value); // pest.toBeCallable.chain
    dumpType($v); // pest.toBeCallable.subject
});

test('toBeIterable', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeIterable();
    dumpType($e->value); // pest.toBeIterable.chain
    dumpType($v); // pest.toBeIterable.subject
});

test('toBeNumeric', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeNumeric();
    dumpType($e->value); // pest.toBeNumeric.chain
    dumpType($v); // pest.toBeNumeric.subject
});

test('toBeScalar', function (): void {
    $v = mixedValue();
    $e = expect($v)->toBeScalar();
    dumpType($e->value); // pest.toBeScalar.chain
    dumpType($v); // pest.toBeScalar.subject
});

test('toBeTrue', function (): void {
    $v = maybeBool();
    $e = expect($v)->toBeTrue();
    dumpType($e->value); // pest.toBeTrue.chain
    dumpType($v); // pest.toBeTrue.subject
});

test('toBeFalse', function (): void {
    $v = maybeBool();
    $e = expect($v)->toBeFalse();
    dumpType($e->value); // pest.toBeFalse.chain
    dumpType($v); // pest.toBeFalse.subject
});

test('toBeNull', function (): void {
    $v = maybeString();
    $e = expect($v)->toBeNull();
    dumpType($e->value); // pest.toBeNull.chain
    dumpType($v); // pest.toBeNull.subject
});

test('toBeInstanceOf', function (): void {
    $v = object();
    $e = expect($v)->toBeInstanceOf(Widget::class);
    dumpType($e->value); // pest.toBeInstanceOf.chain
    dumpType($v); // pest.toBeInstanceOf.subject
});

test('toHaveKey', function (): void {
    $v = payload();
    $e = expect($v)->toHaveKey('id');
    dumpType($e->value); // pest.toHaveKey.chain
    dumpType($v); // pest.toHaveKey.subject
});

test('notToBeNull', function (): void {
    $v = maybeString();
    $e = expect($v)->not->toBeNull();
    dumpType($e->value); // pest.notToBeNull.chain
    dumpType($v); // pest.notToBeNull.subject
});

test('notToBeString', function (): void {
    $v = intOrString();
    $e = expect($v)->not->toBeString();
    dumpType($e->value); // pest.notToBeString.chain
    dumpType($v); // pest.notToBeString.subject
});

test('notToBeInstanceOf', function (): void {
    $v = maybeWidget();
    $e = expect($v)->not->toBeInstanceOf(Widget::class);
    dumpType($e->value); // pest.notToBeInstanceOf.chain
    dumpType($v); // pest.notToBeInstanceOf.subject
});

test('and() starts a new subject', function (): void {
    $e = expect(maybeString())->toBeString()->and(intOrString());
    dumpType($e->value); // pest.and.chain
});
test('the chain is read back to expect()', function (): void {
    $a = maybeString();
    expect($a)->toBeString()->toContain('x');
    dumpType($a); // pest.chained.subject

    $b = maybeString();
    expect($b)->not()->toBeNull();
    dumpType($b); // pest.notMethod.subject
});

test('a step that changes the subject ends the reading', function (): void {
    $a = maybeString();
    expect($a)->toBeString()->and(intOrString())->toBeInt();
    dumpType($a); // pest.andAborts.subject

    $b = items();
    expect($b)->each->toBeInstanceOf(Widget::class);
    dumpType($b); // pest.eachAborts.subject
});
