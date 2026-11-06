(function () {
	var D = window.WPIFY, $ = function (s) { return document.querySelector(s); };

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', D.nonce);
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(D.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
	function boxes() { return Array.prototype.slice.call(document.querySelectorAll('#wpify-pages input[type=checkbox]')); }

	$('#wpify-r-save').addEventListener('click', function () {
post('wpify_save', { renderer_url: $('#wpify-r-url').value }).then(function (r) {
    $('#wpify-r-msg').textContent = r.success ? 'Saved.' : 'Save failed.';
});
	});

	$('#wpify-scan').addEventListener('click', function () {
		var msg = $('#wpify-scan-msg');
		msg.textContent = 'Scanning…';
		post('wpify_scan', { url: $('#wpify-url').value }).then(function (r) {
			if (!r.success) { msg.textContent = r.data.message; return; }
			var pages = r.data.pages;
			msg.textContent = pages.length + ' page(s) found.' + (r.data.shell ? ' WARNING: this Canva site is JavaScript-rendered; set up the Renderer above or the import will be empty.' : '') + (r.data.design ? ' Design link detected: only a preview image can be imported. Publish the design as a Canva Website for editable structure.' : '');
			$('#wpify-pages').innerHTML = pages.map(function (p, i) {
				return '<label><input type="checkbox" data-url="' + esc(p.url) + '" data-label="' + esc(p.title) + '"' + (i === 0 ? ' checked' : '') + '> <strong>' + esc(p.title) + '</strong> <small>' + esc(p.url) + '</small></label>';
			}).join('');
			$('#wpify-n').max = pages.length;
			$('#wpify-step2').style.display = 'block';
		}).catch(function () { msg.textContent = 'Request failed.'; });
	});

	$('#wpify-firstn').addEventListener('click', function () {
		var n = parseInt($('#wpify-n').value, 10) || 1;
		boxes().forEach(function (b, i) { b.checked = i < n; });
	});
	$('#wpify-all').addEventListener('click', function () { boxes().forEach(function (b) { b.checked = true; }); });
	$('#wpify-none').addEventListener('click', function () { boxes().forEach(function (b) { b.checked = false; }); });

	$('#wpify-import').addEventListener('click', function () {
		var btn = this, sel = boxes().filter(function (b) { return b.checked; });
		if (!sel.length) return;
		var builder = document.querySelector('input[name=wpify-builder]:checked').value;
		var log = $('#wpify-log'); log.innerHTML = '';
		btn.disabled = true;
		(function next(i) {
			if (i >= sel.length) { btn.disabled = false; return; }
			var b = sel[i], li = document.createElement('li');
			li.textContent = 'Importing ' + b.dataset.label + '…';
			log.appendChild(li);
			post('wpify_import', {
				url: b.dataset.url, label: b.dataset.label, builder: builder,
				status: $('#wpify-status').value, sideload: $('#wpify-side').checked ? '1' : '0',
				exact: $('#wpify-exact').checked ? '1' : '0', canvas: $('#wpify-canvas').checked ? '1' : '0'
			}).then(function (r) {
				if (r.success) {
					li.className = 'ok';
					li.innerHTML = esc(r.data.title) + ' &mdash; ' + r.data.sections + ' section(s) &middot; <a href="' + esc(r.data.edit) + '" target="_blank">Edit</a>' + (r.data.fallback ? ' <span class="warn">(preview image only)</span>' : '');
				} else { li.className = 'err'; li.textContent = b.dataset.label + ': ' + r.data.message; }
			}).catch(function () { li.className = 'err'; li.textContent = b.dataset.label + ': request failed'; })
			  .then(function () { next(i + 1); });
		})(0);
	});
})();
