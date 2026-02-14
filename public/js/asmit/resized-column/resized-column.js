document.addEventListener("alpine:init", () => {
  Alpine.data("resizedColumn", function (u, c) {
    return {
      tableWrapper: null,
      table: null,
      column: null,
      minColumnWidth: 100,
      maxColumnWidth: 1e3,
      handleBar: null,
      tableWrapperContentSelector: ".fi-ta-content-ctn",
      tableSelector: ".fi-ta-table",
      tableBodyCellPrefix: "fi-ta-cell-",
      debounceTime: 500,
      init() {
        if (
          ((this.column = this.$el),
          (this.table = this.$el.closest(this.tableSelector)),
          (this.tableWrapper = this.$el.closest(
            this.tableWrapperContentSelector
          )),
          !this.column || !this.table || !this.tableWrapper)
        )
          return null;
        this.initializeColumnLayout(), this.onLivewireUpdate();
      },
      initializeColumnLayout() {
        this.column.classList.add("relative", "group/column-resize"),
          this.createHandleBar();
      },
      createHandleBar() {
        (this.handleBar = document.createElement("button")),
          (this.handleBar.type = "button"),
          this.handleBar.classList.add("column-resize-handle-bar");
        let e = this.column.querySelector(".column-resize-handle-bar");
        e && e.remove(),
          this.column.appendChild(this.handleBar),
          this.handleBar.addEventListener(
            "mousedown",
            this.startResize(this.column)
          );
      },
      startResize(e) {
        return (t) => {
          t.preventDefault(), this.handleBar.classList.add("active");
          let i = t.pageX,
            l = Math.round(e.offsetWidth),
            n = Math.round(this.table.offsetWidth),
            o = Math.round(this.tableWrapper.offsetWidth),
            a = 0,
            s = (d) => {
              if (d.pageX === i) return;
              a = Math.round(
                Math.min(
                  this.maxColumnWidth,
                  Math.max(this.minColumnWidth, l + (d.pageX - i) - 16)
                )
              );
              let h = n - l + a;
              (this.table.style.width = `${h > o ? h : "auto"}px`),
                this.applyColumnWidth(e, a),
                this.$dispatch("column-resized");
            },
            r = () => {
              this.handleBar.classList.remove("active"),
                this.debounce(() => {
                  this.$wire.updateColumnWidth(u, a);
                }, this.debounceTime)(),
                document.removeEventListener("mousemove", s),
                document.removeEventListener("mouseup", r);
            };
          document.addEventListener("mousemove", s),
            document.addEventListener("mouseup", r);
        };
      },
      applyColumnWidth(e, t) {
        this.setColumnWidthAttribute(e, t);
        let i = this.tableBodyCellPrefix + c;
        this.table
          .querySelectorAll(`.${this.getEscapedSelectorFromClass(i)}`)
          .forEach((n) => {
            this.setColumnWidthAttribute(n, t), (n.style.overflow = "hidden");
          });
      },
      setColumnWidthAttribute(e, t) {
        (e.style.maxWidth = `${t}px`),
          (e.style.width = `${t}px`),
          (e.style.minWidth = `${t}px`);
      },
      getEscapedSelectorFromClass(e) {
        return !e || typeof e != "string" ? "" : e.replace(/\./g, "\\.");
      },
      debounce(e, t) {
        let i;
        return function (...n) {
          clearTimeout(i),
            (i = setTimeout(() => {
              clearTimeout(i), e(...n);
            }, t));
        };
      },
      onLivewireUpdate() {
        window.Livewire.hook("morph.updated", () => {
          this.initializeColumnLayout();
        });
      },
    };
  });
});
