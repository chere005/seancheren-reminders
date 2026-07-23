function setup() {
  createCanvas(windowWidth, windowHeight);
  background(255);
  noStroke();
  fill(220, 30, 30);
}

function draw() {}

function mousePressed() {
  circle(mouseX, mouseY, 40);
}

function windowResized() {
  resizeCanvas(windowWidth, windowHeight);
  background(255);
}
