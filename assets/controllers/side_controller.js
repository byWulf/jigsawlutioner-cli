import { Controller} from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ['side'];
    openSide() {
        this.application.getControllerForElementAndIdentifier(document.getElementById('stats'), 'stats').openSide(this.sideTarget);
    }
}
