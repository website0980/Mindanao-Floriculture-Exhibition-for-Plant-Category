document.addEventListener("click", (event) => {
  const printButton = event.target.closest("[data-print]");
  if (printButton) {
    window.print();
  }
});

document.addEventListener("submit", (event) => {
  const submitter = event.submitter;
  const message = submitter?.getAttribute("data-confirm");
  if (message && !window.confirm(message)) {
    event.preventDefault();
  }
});

document.addEventListener("input", (event) => {
  const filter = event.target.closest("[data-filter]");
  if (!filter) {
    return;
  }
  const list = document.getElementById(filter.dataset.filter);
  if (!list) {
    return;
  }
  const query = filter.value.trim().toLocaleLowerCase();
  for (const row of list.querySelectorAll("[data-filter-item]")) {
    row.hidden = !row.innerText.toLocaleLowerCase().includes(query);
  }
});
