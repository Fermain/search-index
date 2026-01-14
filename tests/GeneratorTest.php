<?php

namespace SearchIndex\Tests;

use PHPUnit\Framework\TestCase;
use SearchIndex\Generator;
use ReflectionMethod;

class GeneratorTest extends TestCase {

    private function getPrivateMethod( string $name ) : ReflectionMethod {
        $method = new ReflectionMethod( Generator::class, $name );
        $method->setAccessible( true );
        return $method;
    }

    /**
     * @dataProvider urlProvider
     */
    public function testNormaliseUrl( string $input, string $expected ) : void {
        $generator = new Generator();
        $method = $this->getPrivateMethod( 'normaliseUrl' );
        
        $this->assertEquals( $expected, $method->invoke( $generator, $input ) );
    }

    public function urlProvider() : array {
        return [
            'Home URL' => [
                'https://yoti.wordpress.infra.yoti.com',
                '/'
            ],
            'Post URL' => [
                'https://yoti.wordpress.infra.yoti.com/some-post/',
                '/some-post/'
            ],
            'Post with Query' => [
                'https://yoti.wordpress.infra.yoti.com/some-post/?abc=123',
                '/some-post/?abc=123'
            ],
            'Post with Fragment' => [
                'https://yoti.wordpress.infra.yoti.com/some-post/#section',
                '/some-post/#section'
            ],
            'CDN URL (should not be normalised)' => [
                'https://cdn.aws.yoti.com/assets/img.png',
                'https://cdn.aws.yoti.com/assets/img.png'
            ],
            'External URL' => [
                'https://google.com/search',
                'https://google.com/search'
            ],
            'Empty URL' => [
                '',
                ''
            ]
        ];
    }

    public function testNormaliseUrlWithPort() : void {
        global $wp_home_url;
        $wp_home_url = 'http://localhost:8080';
        $generator = new Generator();
        $method = $this->getPrivateMethod( 'normaliseUrl' );
        
        // Matching host and port
        $this->assertEquals( '/page/', $method->invoke( $generator, 'http://localhost:8080/page/' ) );
        
        // Different port
        $this->assertEquals( 'http://localhost:9090/page/', $method->invoke( $generator, 'http://localhost:9090/page/' ) );
        
        // Reset for next tests
        $wp_home_url = null;
    }

    public function testNormaliseHtml() : void {
        $generator = new Generator();
        $method = $this->getPrivateMethod( 'normaliseHtml' );

        $html = '<a href="https://yoti.wordpress.infra.yoti.com/link/">Link</a>';
        $html .= '<img src="https://cdn.aws.yoti.com/img.png" />';
        $html .= '<img src="https://yoti.wordpress.infra.yoti.com/local-img.png" />';

        $expected = '<a href="/link/">Link</a>';
        $expected .= '<img src="https://cdn.aws.yoti.com/img.png" />';
        $expected .= '<img src="/local-img.png" />';

        $this->assertEquals( $expected, $method->invoke( $generator, $html ) );
    }
}
