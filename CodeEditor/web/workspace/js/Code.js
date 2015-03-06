var Code = (function() {
	function Code(tab) {
		this.tab = tab;
		this.action();
	};

	Code.prototype.action = function() {
		var self = this;
		$(window).keydown(function(e) {
			if (e.which == 116 || e.keyCode == 116) {
				e.preventDefault();
				self.compile();
			}
		});
		$("#compileCode").click(function(e) {
			e.preventDefault();
			self.compile();
		});
	};

	Code.prototype.compile = function() {
		var self = this;
		CE.http({
			method: "GET",
			url: CE.serviceUri + "compiling/" + self.tab.currentFileID,
		}).success(function(data) {
			var send = data.file;
			window.open(CE.terminalUri + "terminal/index.html#" + send, "Result", 'height=417,width=645');
		});
	}

	return Code;
})();
