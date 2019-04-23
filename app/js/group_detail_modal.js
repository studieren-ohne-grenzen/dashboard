"use strict";
module.exports = function(group_name, group_mailinglistId) {
    // this could also be retrieved from the TR `dataset` attribute, but list.js doesn't support them
    var groupData = {
        cn: group_name,
        mailinglistId: group_mailinglistId
    };

    var makeLink = function (url, label) {
        if (label === undefined) {
            label = url;
        }
        return '<a href="' + url + '">' + label + '</a>';
    };

    var getListLink = function (dataset) {
        var mailinglist = dataset.mailinglistId + '@lists.studieren-ohne-grenzen.org';
        return makeLink('mailto:' + mailinglist, mailinglist);
    };

    var getOwnCloudLink = function (dataset) {
        return makeLink('https://owncloud.studieren-ohne-grenzen.org/index.php/apps/files/?dir=%2F' + encodeURI(dataset.cn));
    };

    basicModal.show({
        body: "<h1>" + group_name + "</h1>" +
        "<dl>" +
        "<dt>OwnCloud</dt>" +
        "<dd>" + getOwnCloudLink(groupData) + "</dd>" +
        "<dt>Verteiler</dt>" +
        "<dd>" + getListLink(groupData) + "</dd>" +
        "</dl>" +
        "<p>Bitte beachte, dass du in der Gruppe Mitglied sein musst, um Zugriff auf den OwnCloud-Ordner zu erhalten und um über den Verteiler schreiben zu können.</p>",
        closable: true,
        buttons: {
            cancel: {
                'class': basicModal.THEME.xclose,
                fn: basicModal.close
            },
            action: {
                title: 'schließen',
                fn: basicModal.close
            }
        }
    });
}
