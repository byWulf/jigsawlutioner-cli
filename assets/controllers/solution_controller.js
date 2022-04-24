import { Controller} from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ['container', 'showNumbers', 'showProbabilities'];

    toggleNumbers() {
        this.containerTarget.classList.toggle('show-numbers', this.showNumbersTarget.checked);
        this.containerTarget.classList.toggle('show-probabilities', this.showProbabilitiesTarget.checked);
    }

    connect() {
        this.toggleNumbers();
    }
}
