import { Controller} from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ['pieceIndex', 'sideIndex', 'tbody', 'stats'];
    openSide(sideElement) {
        this.hide();

        this.pieceIndexTarget.innerHTML = sideElement.dataset.pieceIndex;
        this.sideIndexTarget.innerHTML = sideElement.dataset.sideIndex;

        this.tbodyTarget.innerHTML = '';

        const probabilities = JSON.parse(sideElement.dataset.context).probabilities;
        for (let i in probabilities) {
            const row = document.createElement('tr');

            const side = document.createElement('td');
            side.innerText = i;
            row.appendChild(side);

            const probability = document.createElement('td');
            probability.innerText = probabilities[i];
            row.appendChild(probability);

            this.tbodyTarget.appendChild(row);
        }

        this.statsTarget.style.display = 'block';
        sideElement.classList.add('selected')
    }

    hide() {
        this.statsTarget.style.display = 'none';
        console.log(document, document.querySelector('.piece-overlay-side.selected'));
        if (document.querySelector('.piece-overlay-side.selected')) {
            document.querySelector('.piece-overlay-side.selected').classList.remove('selected');
        }
    }
}
