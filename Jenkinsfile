pipeline {
    agent any

    stages {
        stage('Checkout') {
            steps {
                git 'git@github.com:wyholdings/wyhds.git'
            }
        }

        stage('Build') {
            steps {
                sh 'mvn clean install'
            }
        }

        stage('Test') {
            steps {
                sh 'mvn test'
            }
        }

        stage('Deploy') {
            steps {
                sh 'scp target/your-app.jar user@your-server:/path/to/deploy'
            }
        }
    }
}
