<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\Utils\Queries\Foo;
use Illuminate\Foundation\Testing\TestResponse;

class ValidationTest extends TestCase
{
    protected $schema = '
    type Query {
        foo(
            email: String = "hans@peter.rudolf" @rules(apply: ["email"])
            required: String @rules(apply: ["required"])
            stringList: [String!] @rulesForArray(apply: ["array", "max:1"])
            input: [Bar] @rulesForArray(apply: ["min:3"])
            list: [String]
                @rules(apply: ["required", "email"])
                @rulesForArray(apply: ["max:2"])
        ): Int
        
        password(
            id: String
            password: String
                @trim
                @rules(apply: ["min:6", "max:20", "required_with:id"])
                @bcrypt
            bar: Bar
                @rules(apply: ["required_if:id,bar"])
        ): String @field(resolver: "Tests\\\\Integration\\\\ValidationTest@resolvePassword")

        email(
            userId: ID!
            email: Email!
        ): String @field(resolver: "Tests\\\\Integration\\\\ValidationTest@resolveEmail")
    }

    input Email {
        emailAddress: String! @rules(apply: ["email"])
        business: Boolean @rules(apply: ["required"])
    }
    
    input Bar {
        foobar: Int @rules(apply: ["integer", "max:10"])
        self: Bar
        withRequired: Baz
        optional: String
    }
    
    input Baz {
        barbaz: Int
        invalidDefault: String = "invalid-mail" @rules(apply: ["email"])
        required: Int @rules(apply: ["required"])
    }
    ';

    /**
     * @param  mixed  $root
     * @param  mixed[]  $args
     * @return string
     */
    public function resolvePassword($root, array $args): string
    {
        return $args['password'] ?? 'no-password';
    }

    /**
     * @param  mixed  $root
     * @param  mixed[]  $args
     * @return string
     */
    public function resolveEmail($root, array $args): string
    {
        return Arr::get($args, 'email.emailAddress', 'no-email');
    }

    /**
     * @test
     */
    public function itValidatesDifferentPathsIndividually(): void
    {
        $result = $this->graphQL('
        {
            foo(
                input: [
                    {
                        foobar: 123
                    }
                    {
                        self: {
                            foobar: 12
                        }
                    }
                    {
                        withRequired: {
                            barbaz: 23
                        }
                    }    
                ]
            )
        }
        ');

        $this->assertValidationKeysSame([
            'required',
            'input.0.foobar',
            'input.1.self.foobar',
            'input.2.withRequired.invalidDefault',
            'input.2.withRequired.required',
        ], $result);
    }

    /**
     * @test
     */
    public function itValidatesList(): void
    {
        $result = $this->graphQL('
        {
            foo(
                list: [
                    "valid_email@example.com"
                    null
                    "invalid_email"
                ]
            )
        }
        ');

        $this->assertValidationKeysSame([
            'required',
            'list',
            'list.1',
            'list.2',
        ], $result);
    }

    /**
     * @test
     */
    public function itValidatesInputCount(): void
    {
        $result = $this->graphQL('
        {
            foo(
                stringList: [
                    "asdf",
                    "one too many"
                ]
                input: [{
                    foobar: 1
                }]
            )
        }
        ');

        $this->assertValidationKeysSame([
            'required',
            'stringList',
            'input',
        ], $result);

        $this->assertTrue(
            Str::endsWith(
                $result->jsonGet('errors.0.extensions.validation.stringList.0'),
                'may not have more than 1 items.'
            )
        );

        $this->assertTrue(
            Str::endsWith(
                $result->jsonGet('errors.0.extensions.validation.input.0'),
                'must have at least 3 items.'
            ),
            'Validate size as an array by prepending the rules with the "array" validation'
        );
    }

    /**
     * @test
     */
    public function itPassesIfNothingRequiredIsMissing(): void
    {
        $this->graphQL('
        {
            foo(required: "foo")
        }
        ')->assertJson([
            'data' => [
                'foo' => Foo::THE_ANSWER,
            ],
        ]);
    }

    /**
     * @test
     */
    public function itEvaluatesArgDirectivesInDefinitionOrder(): void
    {
        $validPasswordResult = $this->graphQL('
        {
            password(password: " 1234567 ")
        }
        ');
        $password = $validPasswordResult->jsonGet('data.password');

        $this->assertNotSame(' 1234567 ', $password);
        $this->assertTrue(password_verify('1234567', $password));

        $invalidPasswordResult = $this->graphQL('
        {
            password(password: " 1234 ")
        }
        ')->assertJson([
            'data' => [
                'password' => null,
            ],
        ]);

        $this->assertValidationKeysSame(['password'], $invalidPasswordResult);
    }

    /**
     * @test
     */
    public function itEvaluatesConditionalValidation(): void
    {
        $validPasswordResult = $this->graphQL('
        {
            password
        }
        ');

        $this->assertSame('no-password', $validPasswordResult->jsonGet('data.password'));

        $invalidPasswordResult = $this->graphQL('
        {
            password(id: "foo")
        }
        ')->assertJson([
            'data' => [
                'password' => null,
            ],
        ]);

        $this->assertValidationKeysSame(['password'], $invalidPasswordResult);
    }

    /**
     * @test
     */
    public function itEvaluatesInputArgValidation(): void
    {
        $result = $this->graphQL('
        {
            password(id: "bar", password: "123456")
        }
        ')->assertJson([
            'data' => [
                'password' => null,
            ],
        ]);

        $this->assertValidationKeysSame(['bar'], $result);
    }

    /**
     * @test
     */
    public function itEvaluatesNonNullInputArgValidation(): void
    {
        $this->graphQL('
        {
            email(
                userId: 1
                email: {
                    emailAddress: "john@doe.com"
                    business: true
                }
            )
        }
        ')->assertJson([
            'data' => [
                'email' => 'john@doe.com',
            ],
        ]);

        $invalidEmailResult = $this->graphQL('
        {
            email(
                userId: 1
                email: {
                    emailAddress: "invalid_email_address"
                }
            )
        }
        ')->assertJson([
            'data' => [
                'email' => null,
            ],
        ]);
        $this->assertValidationKeysSame([
            'email.emailAddress',
            'email.business',
        ], $invalidEmailResult);
    }

    /**
     * @test
     */
    public function itErrorsIfSomethingRequiredIsMissing(): void
    {
        $result = $this->graphQL('
        {
            foo
        }
        ')->assertJson([
            'data' => [
                'foo' => null,
            ],
        ]);

        $this->assertValidationKeysSame([
            'required',
        ], $result);
    }

    /**
     * Assert that the returned result contains an exactly defined array of validation keys.
     *
     * @param  array  $keys
     * @param  \Illuminate\Foundation\Testing\TestResponse  $result
     * @return void
     */
    protected function assertValidationKeysSame(array $keys, TestResponse $result): void
    {
        $validation = $result->jsonGet('errors.0.extensions.validation');

        $this->assertSame($keys, array_keys($validation));
    }
}
