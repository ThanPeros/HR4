class AttritionApp {
    constructor() {
        this.predictor = new AttritionPredictor();
        this.employees = [];
        this.init();
    }

    async init() {
        await this.loadEmployees();
        this.setupEventListeners();
        this.renderEmployeeTable();
    }

    async loadEmployees() {
        try {
            const response = await fetch('http://localhost:3000/api/employees');
            this.employees = await response.json();
            this.populateEmployeeSelect();
        } catch (error) {
            console.error('Error loading employees:', error);
        }
    }

    populateEmployeeSelect() {
        const select = document.getElementById('employeeSelect');
        select.innerHTML = '<option value="">Select Employee</option>';
        
        this.employees.forEach(emp => {
            const option = document.createElement('option');
            option.value = emp.id;
            option.textContent = `${emp.id} - ${emp.department} - ${emp.job_title}`;
            select.appendChild(option);
        });
    }

    setupEventListeners() {
        document.getElementById('predictBtn').addEventListener('click', () => {
            this.predictAttrition();
        });

        document.getElementById('trainBtn').addEventListener('click', () => {
            this.retrainModel();
        });
    }

    async predictAttrition() {
        const employeeId = document.getElementById('employeeSelect').value;
        
        if (!employeeId) {
            alert('Please select an employee');
            return;
        }

        try {
            // Get employee features from backend
            const response = await fetch('http://localhost:3000/api/predict', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ employeeId: parseInt(employeeId) })
            });

            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error);
            }

            // Make prediction
            const probability = await this.predictor.predict(data.features);
            this.displayResult(data.employee, probability, data.features);
            
        } catch (error) {
            console.error('Prediction error:', error);
            alert('Prediction failed: ' + error.message);
        }
    }

    displayResult(employee, probability, features) {
        const resultDiv = document.getElementById('result');
        const detailsDiv = document.getElementById('predictionDetails');
        const riskDiv = document.getElementById('riskLevel');
        const confidenceDiv = document.getElementById('confidence');
        
        const risk = this.predictor.getRiskLevel(probability);
        
        detailsDiv.innerHTML = `
            <p><strong>Employee:</strong> ID ${employee.id}</p>
            <p><strong>Department:</strong> ${employee.department}</p>
            <p><strong>Job Title:</strong> ${employee.job_title}</p>
            <p><strong>Tenure:</strong> ${Math.round(employee.tenure_days / 365 * 10) / 10} years</p>
            <p><strong>Performance:</strong> ${employee.performance_rating}/10</p>
        `;
        
        riskDiv.textContent = `Attrition Risk: ${risk.level}`;
        riskDiv.className = `risk-level ${risk.color}`;
        
        confidenceDiv.textContent = `Confidence: ${(probability * 100).toFixed(1)}%`;
        
        resultDiv.classList.remove('hidden');
    }

    async retrainModel() {
        try {
            const response = await fetch('http://localhost:3000/api/retrain', {
                method: 'POST'
            });
            
            if (response.ok) {
                alert('Model retraining started!');
            } else {
                throw new Error('Retraining failed');
            }
        } catch (error) {
            console.error('Retraining error:', error);
            alert('Retraining failed: ' + error.message);
        }
    }

    renderEmployeeTable() {
        const tbody = document.querySelector('#employeesTable tbody');
        tbody.innerHTML = '';
        
        this.employees.forEach(emp => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${emp.id}</td>
                <td>${emp.name || 'N/A'}</td>
                <td>${emp.department}</td>
                <td>${emp.status}</td>
                <td>--</td>
            `;
            tbody.appendChild(row);
        });
    }
}

// Initialize app when page loads
document.addEventListener('DOMContentLoaded', () => {
    new AttritionApp();
});