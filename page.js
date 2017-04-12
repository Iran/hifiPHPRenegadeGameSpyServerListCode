$(function () {
  if (!$('#servers')[0]) {
    return;
  }
  function refreshServers() {
    $.getJSON('renegade-api?timeleft=&Time_Remaining=&_players=1&_callback=?', function (data) {

      $('#servers tbody').empty();

      for (var i in data) {
        var server = data[i];

        var row = $('<tr />').addClass('server');

        if (server.numplayers > 0) {
          row.addClass('players');
          row.data('players', server.players);

          row.mousemove(function (ev) {
            $('#players').css({left: (ev.pageX + 10) + 'px', top: (ev.pageY + 10) + 'px'});
          }).hover(function (ev) {
            var players = $(this).data('players');
            $('#players tbody').empty();
            for (var i in players) {
              var row = $('<tr />');
              $('<td />').addClass('name').text(players[i].name).appendTo(row);
              $('<td />').addClass('frags').text(players[i].score).appendTo(row);
              $('<td />').addClass('ping').text(players[i].team).appendTo(row);
              row.appendTo($('#players tbody'));
            }
            $('#players').css('margin-top', '-' + ($('#players').height() / 2) + 'px');
            $('#players').show();
          }, function (ev) {
            $('#players').hide();
          });
        }

        var timeleft = (server.timeleft ? server.timeleft : (server['Time Remaining'] ? server['Time Remaining'] : ''));
        var mapname = server.mapname || '';
        $('<td />').append($('<img />').attr('src', 'assets/images/flags/' + server.countrycode.toLowerCase() + '.png').attr('alt', server.country)).append(' ').append(document.createTextNode(server.hostname)).appendTo(row);
        if (server.password == 1) {
          $('<td />').append($('<img />').attr('src', 'assets/images/lock.png')).appendTo(row);
        } else {
          $('<td />').appendTo(row);
        }
        $('<td />').append($('<a />').attr('href', 'renegade://' + server.ip + ':' + server.hostport).text(server.ip + ':' + server.hostport)).appendTo(row);
        $('<td />').text(mapname.replace(/\.mix/, '')).appendTo(row);
        $('<td />').css('text-align', 'right').text(timeleft).appendTo(row);
        $('<td />').css('text-align', 'right').text(server.numplayers + ' / ' + server.maxplayers).appendTo(row);

        row.appendTo('#servers tbody');
      }
    });
  }

  refreshServers();

  $('#refresh').click(function (ev) {
    ev.preventDefault();
    refreshServers();
    return false;
  });
});