"use strict";
module.exports = function(password_input, password_repeat_input,
                          validation_message_container, strength_indicator_container) {
    var password = document.getElementById(password_input),
        password_repeat = document.getElementById(password_repeat_input),
        validation_message = document.getElementById(validation_message_container),
        strength_indicator = document.getElementById(strength_indicator_container);

    password.onkeyup = function() {
        strength_indicator.innerHTML = passwordStrength(this.value);
    };

    password_repeat.onkeyup = function() {
      validatePass(password, password_repeat, validation_message)
    };
};
function validatePass(password, password_repeat, validation_message) {
    var colorOK = '#66cc66',
        colorWrong = '#ff6666';

    if (password.value == password_repeat.value) {
        password_repeat.style.backgroundColor = colorOK;
        validation_message.style.color = colorOK;
        validation_message.innerHTML = '';
    } else {
        password_repeat.style.backgroundColor = colorWrong;
        validation_message.style.color = colorWrong;
        validation_message.innerHTML = 'Passw&ouml;rter stimmen nicht &uuml;berein';
    }
}

function passwordStrength(password) {
    var result = zxcvbn(password);
    var text = '';
    switch (result.score) {
        case 0:
            text = 'sehr schwach';
            break;
        case 1:
            text = 'schwach';
            break;
        case 2:
            text = 'naja';
            break;
        case 3:
            text = 'ganz okay';
            break;
        case 4:
            text = 'sicheres Passwort gew&auml;hlt!';
    }
    return text;
}