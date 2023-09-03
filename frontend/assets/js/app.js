$(function () {
	var loading = $('.loading');
	var updateLoading = function () {
		var dots = loading.find('b');
		var length = dots.text().length;
		length++;
		if (length > 3) {
			length = 0;
		}
		dots.text('.'.repeat(length));
		if (loading.is(':visible')) {
			setTimeout(updateLoading, 200);
		}
	};
	updateLoading();

	var switchModel = function (control) {
		$('ul.models li.active').removeClass('active');
		control.parent().addClass('active');
		$('.group.active').removeClass('active');
		var groupId = control.attr('data-id');
		$('.group[data-id="' + groupId + '"]').addClass('active');
		if ($('#nav').is('.in')) {
			$('[data-target="#nav"]').click();
			$("html, body").animate({scrollTop: 0}, 250);
		}
		var parent = control.closest('[data-more]');
		if (parent.length) {
			parent.addClass('active');
		}
		return groupId;
	};

	$(document).on('click', 'ul.models a', function (e) {
		e.preventDefault();
		var control = $(this);
		if (control.is('[data-id]')) {
			var groupId = switchModel(control);
			history.pushState(null, null, '#' + groupId);
		}
	});

	$.get({
		url: config.rootUrl + '/files/latest.json',
		cache: false,
		success: function (data) {
			var target = $('.footer .latest-updates');
			target.find('[data-latest-updates]').text(data.message);
			target.show();
		}
	});

	var navigation = $('.navbar');
	var initialNavigationHeight = navigation.height();

	var rearrangeNavigation = function () {
		var items = [];
		var nav = $('ul.models');

		var otherNavItem = nav.find('[data-more]');
		otherNavItem.show();

		nav.find('li:not(.dropdown)').each(function () {
			var navItem = $(this);
			navItem.remove();
			items.push(navItem);
		})

		$.each(items, function () {
			var navItem = $(this);
			nav.append(navItem);

			if (navigation.height() > initialNavigationHeight) {
				navItem.remove();
				otherNavItem.find('ul').append(navItem);
			}
		});

		otherNavItem.remove();
		nav.append(otherNavItem);
		if (!otherNavItem.find('li').length) {
			otherNavItem.hide();
		}

		if (nav.find('li.active:not(.dropdown)').closest('[data-more]').length) {
			otherNavItem.addClass('active');
		} else {
			otherNavItem.removeClass('active');
		}
	};
	$(window).on('resize', rearrangeNavigation);

	$.get({
		url: config.rootUrl + '/files/changelog.json',
		cache: false,
		success: function (data) {
			var nav = $('ul.models');
			var navTemplate = nav.find('li.template');
			var content = $('.content');

			var counter = 0;
			for (var model in data) {
				counter++;

				var navItem = navTemplate.clone();
				navItem.removeClass('template');
				var navLink = navItem.find('a');
				navLink.text(model);
				var groupId = simplify(model);
				navLink.attr('data-id', groupId);
				if (counter === 1) {
					navItem.addClass('active');
				}
				nav.append(navItem);

				var wrapper = $('<div class="group">');
				var headline = $('<span>').text(model).prepend('<b>AiXun</b> ');
				wrapper.append($('<h2>').append(headline.clone(true)));
				for (var index in data[model]) {
					var item = $('<div class="item">');
					var version = data[model][index]['version'];
					var match = /^[a-z0-9]+:v?([a-z0-9.]+)$/i.exec(version);
					if (match) {
						version = match[1];
					}
					version += ' (' + data[model][index]['time'] + ')';
					var name = $('<h3>');
					name.text(version);
					var fileName = data[model][index]['fileName'];
					if (fileName) {
						var link = $('<a>');
						var url = config.rootUrl + '/firmware/' + encodeURIComponent(fileName);
						link.attr('href', url);
						link.text(fileName);
						name.append(link);
					}
					item.append(name);
					item.append(formatNoteText(data[model][index]['en_note']));
					item.append(formatNoteText('\n\n' + data[model][index]['ch_note']));
					wrapper.append(item);
				}
				if (!data[model].length) {
					wrapper.append('No versions found.');
				}
				wrapper.attr('data-id', groupId);
				if (counter === 1) {
					wrapper.addClass('active');
				}
				content.append(wrapper);
			}

			rearrangeNavigation();

			loading.hide();

			var hash = window.location.hash;
			if (hash) {
				groupId = hash.substring(1, hash.length);
				var control = $('ul.models a[data-id="' + groupId + '"]');
				if (control.length) {
					switchModel(control);
				}
			}
		},
		error: function (jqXHR, textStatus) {
			$('.content').append($('<h1>').text('Loading failed: ' + textStatus + ', please try again later.'));
			loading.hide();
		}
	});

	function formatNoteText(content) {
		content = content.replace(';r\n', ';\r\n');
		return document.createTextNode(content);
	}

	function simplify(string) {
		string = string.replace(/[^a-z0-9]/ig, '-');
		string = string.replace(/-+/g, '-');
		if (string[0] === '-') {
			string = string.substring(1, string.length);
		}
		if (string[string.length - 1] === '-') {
			string = string.substring(0, string.length - 1);
		}
		return string.toLowerCase();
	}
});
