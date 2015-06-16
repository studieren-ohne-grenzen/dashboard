# The Dashboard
Simple dashboard for different management tasks, works on our LDAP tree

# Getting started

## Requirements
- [PHP](https://php.net)
- [Composer](https://getcomposer.org)
- [NPM](https://npmjs.org/)
- [Bower](http://bower.io/)

## Installation
1. Clone the repository: `git clone https://github.com/studieren-ohne-grenzen/dashboard.git`
2. Run `composer install`
3. Run `npm install`
4. Run `bower install`
5. Run `brunch w --server` and start hacking!

## Configuration
- The logging component *Monolog* expects a writable directory `logs` in the root. 
- TODO: LDAP settings etc

## Building
You probably want to use [Brunch](http://brunch.io) for build management. Install it by running `npm install -g brunch`.
Brunch basically has two different modes, building (`b` switch) and watching (`w` switch). If you just want to have a
look, run `brunch w --server` and open `0.0.0.0:3000` in your favorite web browser. Changes to the assets will be
compiled and injected on the fly.
You can also run `brunch b --production` to minify and mangle all CSS and JS files.

## Deployment

TODO