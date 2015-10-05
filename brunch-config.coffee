exports.config =
  paths:
    public: 'public'
    watched: ['app']
  files:
    javascripts:
      joinTo:
        'js/app.js': /^app\/js/
        'js/vendor.js': /^bower_components/
    stylesheets:
      joinTo:
        'css/app.css': /^app\/css/
        'css/vendor.css': /^bower_components/
  conventions:
    ignored: /zxcvbn\.js$/
    vendor: /(^bower_components|node_modules)[\\/]/
  plugins:
    afterBrunch: [
      'cp bower_components/zxcvbn/dist/zxcvbn.js public/js'
    ]
    uglify:
      mangle: true
      compress:
        global_defs:
          DEBUG: false
    cleancss:
      keepSpecialComments: '*'
  server:
    command: 'php -S 0.0.0.0:3000 -t public'