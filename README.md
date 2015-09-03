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
- LDAP etc. is configured in `app/config.php`, please see `app/config.php.sample` for details
- You should use *STARTTLS* with the LDAP configuration, as suggested. You may need to alter your local `ldap.conf` file so the certificate will be accepted. See [php.net](https://secure.php.net/manual/de/function.ldap-start-tls.php#94893) and this [DigitalOcean guide](https://www.digitalocean.com/community/tutorials/how-to-encrypt-openldap-connections-using-starttls) for details.

## Building
You probably want to use [Brunch](http://brunch.io) for build management. Install it by running `npm install -g brunch`.
Brunch basically has two different modes, building (`b` switch) and watching (`w` switch). If you just want to have a
look, run `brunch w --server` and open `0.0.0.0:3000` in your favorite web browser. Changes to the assets will be
compiled and injected on the fly.
You can also run `brunch b --production` to minify and mangle all CSS and JS files.

## Deployment

Simply run `./deploy.sh` from the root directory. You need to setup a local SSH alias called `sogserver` which will be used to rsync all files to the server. See the `deploy_ignore.txt` file for a list of folders/files which will not be copied. 

## Overview

Here is a general overview of the directory structure:

```
<root>
|
| app/                                The main application folder where nearly all developemnt is happening
    | assets/                         Static assets, such as images
    | css/                            Our custom CSS files
    | js/                             Our custom JS files
    | setup/                          Scripts to setup the environment, such as creating the sqlite database
    | sog/                            PSR-0 namespaced application files, autoloaded using Composer
    | config.php.sample               Rename the sample file to config.php for usage
    | create_sieve_forwardings.sh     Bash script to create the necessary files for initial sieve forwarding, needs sudo
    | dashboard.db (not in git)       The sqlite database.
    | services.php                    Service definitions for Silex application
| bower_components/ (not in git)      Bower files
| doc/                                Documentation
| logs/ (not in git)                  Used by Monolog
| node_modules/ (not in git)          NPM files
| public/                             This is the document root for the webserver, Brunch will compile and copy all assets (images, CSS, JS...) is folder
    | .htaccess-dist                  mod_rewrite for nicer URLs and more, should be setup directly in the vhost file
    | index.php                       The main runtime for the application
    | <everything else>               All other folders are copied here by Brunch upon building, changes made to files here will be lost 
| vendor/ (not in git)                Composer files
| views/                              Twig templates
| bower.json                          Frontend dependencies, such as List.js and PureCSS
| brunch-config.coffee                Brunch config file
| composer.json                       PHP dependencies, such as Silex
| composer.lock                       A snapshot of the currently used version numbers of the composer dependencies
| deploy.sh                           Script for easy deployment
| deploy_ignore.txt                   Lists files (with globbing) which should not be deployed with rsync 
| LICENSE                             Go ahead, read it. It's not long.
| package.json                        Development dependencies, such as plugins for Brunch
| phpdoc.dist.xml                     Settings some defaults for phpdoc, such as target and project title
| README.md                           Hi, it's me!
```
