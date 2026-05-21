/**
 * Dynamic milestone loading for the "Across Users" form.
 *
 * When the project select changes, fetches milestones from the API
 * and repopulates the milestone dropdown.
 *
 * Expects the project select to have a [data-milestones-url] attribute
 * containing a URL template with __PROJECT_ID__ as placeholder.
 */
const projectSelect = document.querySelector("[data-milestones-url]");

if (projectSelect) {
    const urlTemplate = projectSelect.dataset.milestonesUrl;
    const milestoneSelect = document.getElementById("across_users_milestone");

    projectSelect.addEventListener("change", function () {
        const projectId = this.value;

        if (!projectId || !milestoneSelect) {
            if (milestoneSelect) {
                milestoneSelect.innerHTML = '<option value="">None</option>';
            }
            return;
        }

        const url = urlTemplate.replace("__PROJECT_ID__", projectId);

        fetch(url)
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                milestoneSelect.innerHTML = "";
                data.forEach(function (item) {
                    const opt = document.createElement("option");
                    opt.value = item.value;
                    opt.textContent = item.label;
                    milestoneSelect.appendChild(opt);
                });
            });
    });
}
