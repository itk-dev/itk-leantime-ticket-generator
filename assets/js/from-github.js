/**
 * Progressive reveal for the "From GitHub" setup form.
 *
 * The source radio group is enabled only once both selects have a value.
 * The Continue submit button is enabled only once both selects have a value
 * AND a source radio is checked.
 *
 * The GitHub repo dropdown is wrapped with Choices.js to provide
 * client-side search/filter over potentially long org repo lists.
 */
import Choices from "choices.js";
import "choices.js/public/assets/styles/choices.min.css";

const form = document.querySelector("[data-github-setup]");

if (form) {
    const repoSelect = form.querySelector("[data-github-repo]");
    const projectSelect = form.querySelector("[data-github-project]");
    const sourceFieldset = form.querySelector("[data-github-source]");
    const sourceInputs = form.querySelectorAll("[data-github-source-input]");
    const continueButton = form.querySelector("[data-github-continue]");

    new Choices(repoSelect, {
        searchEnabled: true,
        searchPlaceholderValue: "Search repositories…",
        itemSelectText: "",
        shouldSort: false,
    });

    function bothSelected() {
        return Boolean(repoSelect.value) && Boolean(projectSelect.value);
    }

    function sourcePicked() {
        for (const input of sourceInputs) {
            if (input.checked) {
                return true;
            }
        }
        return false;
    }

    function update() {
        const ready = bothSelected();
        sourceFieldset.disabled = !ready;

        if (!ready) {
            for (const input of sourceInputs) {
                input.checked = false;
            }
        }

        continueButton.disabled = !(ready && sourcePicked());
    }

    repoSelect.addEventListener("change", update);
    projectSelect.addEventListener("change", update);
    for (const input of sourceInputs) {
        input.addEventListener("change", update);
    }

    update();
}
