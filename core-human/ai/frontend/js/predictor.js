class AttritionPredictor {
    constructor() {
        this.model = null;
        this.isModelLoaded = false;
        this.loadModel();
    }

    async loadModel() {
        try {
            // In a real application, you would load a pre-trained model
            // For now, we'll create a simple model for demonstration
            this.model = await this.createModel();
            this.isModelLoaded = true;
            console.log('Model loaded successfully');
        } catch (error) {
            console.error('Error loading model:', error);
        }
    }

    async createModel() {
        const model = tf.sequential();
        
        model.add(tf.layers.dense({
            units: 32,
            activation: 'relu',
            inputShape: [11] // 11 features based on our data
        }));
        
        model.add(tf.layers.dense({
            units: 16,
            activation: 'relu'
        }));
        
        model.add(tf.layers.dense({
            units: 1,
            activation: 'sigmoid'
        }));
        
        model.compile({
            optimizer: 'adam',
            loss: 'binaryCrossentropy',
            metrics: ['accuracy']
        });
        
        return model;
    }

    async predict(features) {
        if (!this.isModelLoaded) {
            throw new Error('Model not loaded yet');
        }

        const tensor = tf.tensor2d([features]);
        const prediction = this.model.predict(tensor);
        const probability = await prediction.data();
        tensor.dispose();
        prediction.dispose();
        
        return probability[0];
    }

    getRiskLevel(probability) {
        if (probability < 0.3) return { level: 'Low', color: 'risk-low' };
        if (probability < 0.7) return { level: 'Medium', color: 'risk-medium' };
        return { level: 'High', color: 'risk-high' };
    }
}